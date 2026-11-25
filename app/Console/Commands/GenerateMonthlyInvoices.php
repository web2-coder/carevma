<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BillingCycle;
use App\Services\ClientTimezoneService;
use App\Services\InvoiceGenerator;
use App\Services\InvoiceAutoGenerationSchedule;
use App\Services\OperationalNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenerateMonthlyInvoices extends Command
{
    private const RECOVERY_DAYS = 7;
    private const INITIAL_RETRY_HOURS = 6;
    private const DAILY_RECOVERY_MINUTES = 15;

    protected $signature = 'invoices:generate-monthly
        {--date= : Override run date for testing (Y-m-d)}
        {--force : Run regardless of the Doctor-specific shift/time gate}';

    protected $description = 'Generate duplicate-safe monthly draft invoices after each Doctor final cycle-end VA shift is complete';

    public function handle(
        InvoiceGenerator $generator,
        OperationalNotifier $notifier,
        ClientTimezoneService $timezones,
        InvoiceAutoGenerationSchedule $autoSchedule,
    ): int {
        $created = collect();
        $noBillableAttendance = 0;
        $failed = 0;
        $notDue = 0;

        if (!BillingCycle::autoGenerate() && !$this->option('force')) {
            $this->info('Automatic invoice generation is switched off in Invoice Settings; nothing to do.');
            return self::SUCCESS;
        }

        $businessZone = $timezones->businessTimezone();
        $referenceNow = $this->option('date')
            ? Carbon::parse($this->option('date').' 00:00:00', $businessZone)
            : $timezones->now($businessZone);
        $effectiveNowUtc = $referenceNow->copy()->utc();
        $manualRun = (bool) $this->option('force') || (bool) $this->option('date');

        if (!$manualRun && !$this->billingWindowCouldBeActive($referenceNow, $effectiveNowUtc)) {
            $this->info('Invoice run skipped: no billing cycle is inside a due/recovery window.');
            return self::SUCCESS;
        }

        $openStart = BillingCycle::openStart($referenceNow);
        $openEnd = BillingCycle::endOf($openStart)->toDateString();
        [$completedStart, $completedEnd] = BillingCycle::latestCompleted($referenceNow);

        if ($manualRun) {
            $clients = Client::query()->orderBy('id')->cursor();
            $openDueMap = collect();
            $completedDueMap = collect();
            $openAttendanceMap = collect();
            $completedAttendanceMap = collect();
            $batchCursorKey = null;
            $lastClientId = null;
        } else {
            $batchSize = min(max((int) config('carevma.performance.invoice_cron_client_batch', 1000), 100), 5000);
            $batchCursorKey = 'carevma:invoice-cron-cursor:'.$openEnd.':'.$completedEnd;
            $afterId = (int) Cache::get($batchCursorKey, 0);
            $clients = Client::query()->where('id', '>', $afterId)->orderBy('id')->limit($batchSize)->get();
            if ($clients->isEmpty() && $afterId > 0) {
                Cache::put($batchCursorKey, 0, now()->addDays(14));
                $clients = Client::query()->orderBy('id')->limit($batchSize)->get();
            }
            if ($clients->isEmpty()) {
                $this->info('Invoice run complete: no Doctors/Clients found.');
                return self::SUCCESS;
            }
            $lastClientId = (int) $clients->last()->id;
            $clientIds = $clients->pluck('id')->map(fn ($id) => (int) $id)->all();
            $openDueMap = $autoSchedule->dueAtForClients($clients, $openEnd);
            $completedDueMap = $autoSchedule->dueAtForClients($clients, $completedEnd);
            $openAttendanceMap = $autoSchedule->openAttendanceCountsForClients($clientIds, $openEnd);
            $completedAttendanceMap = $autoSchedule->openAttendanceCountsForClients($clientIds, $completedEnd);
        }

        foreach ($clients as $client) {
            // Keep failure handling safe even when period resolution itself throws.
            // Without these defaults the catch block can reference undefined values
            // and abort the whole monthly run instead of moving to the next Doctor.
            $periodStart = null;
            $periodEnd = null;

            try {
                if ($manualRun) {
                    [$periodStart, $periodEnd] = $this->periodDueForClient($client, $referenceNow, $effectiveNowUtc, $autoSchedule);
                } else {
                    [$periodStart, $periodEnd] = $this->periodDueForClientFromMaps(
                        $client,
                        $effectiveNowUtc,
                        $openStart->toDateString(),
                        $openEnd,
                        $completedStart,
                        $completedEnd,
                        $openDueMap,
                        $completedDueMap
                    );
                }

                if (!$periodStart || !$periodEnd) {
                    $notDue++;
                    continue;
                }

                if (!$manualRun) {
                    $openAttendance = (int) (($periodEnd === $openEnd ? $openAttendanceMap : $completedAttendanceMap)->get($client->id, 0));
                    if ($openAttendance > 0) {
                        $notDue++;
                        continue;
                    }
                }

                // Snapshot IDs before generation: an existing draft returned after
                // a retry/VA append is NOT a new invoice and must never notify again.
                $previousInvoiceIds = Invoice::query()
                    ->where('client_id', $client->id)
                    ->where('auto_generated', true)
                    ->whereDate('period_end', $periodEnd)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();
                $invoices = $generator->generateMany($client, $periodStart, $periodEnd, true);
                if ($invoices->isNotEmpty()) {
                    if (Schema::hasTable('invoice_generation_failures')) {
                        DB::table('invoice_generation_failures')->where('client_id', $client->id)->where('billing_month', Carbon::parse($periodEnd)->format('Y-m'))->whereNull('resolved_at')->update(['resolved_at' => now(), 'updated_at' => now()]);
                    }
                    foreach ($invoices as $invoice) {
                        if (!in_array((int) $invoice->id, $previousInvoiceIds, true)) {
                            $created->push($invoice->loadMissing('salaries'));
                        }
                    }
                } else {
                    $noBillableAttendance++;
                }
            } catch (\Throwable $e) {
                $failed++;
                if ($periodStart && $periodEnd && Schema::hasTable('invoice_generation_failures')) {
                    DB::table('invoice_generation_failures')->updateOrInsert(
                        ['client_id' => $client->id, 'billing_month' => Carbon::parse($periodEnd)->format('Y-m')],
                        ['period_start' => Carbon::parse($periodStart)->toDateString(), 'period_end' => Carbon::parse($periodEnd)->toDateString(), 'error_message' => mb_substr($e->getMessage(), 0, 2000), 'failed_at' => now(), 'resolved_at' => null, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
                Log::error('Automatic invoice generation failed for client.', [
                    'client_id' => $client->id,
                    'client_timezone' => $timezones->forClient($client),
                    'exception' => $e,
                ]);
            }
        }

        if (!$manualRun && $batchCursorKey && $lastClientId) {
            Cache::put($batchCursorKey, $lastClientId, now()->addDays(14));
        }

        // One notification per *new automatic invoice ID* and recipient, forever.
        // Re-running the command every minute, retrying, or appending a late VA to
        // an existing draft never causes another notification for that invoice.
        // Next cycle creates a new invoice ID, so it notifies normally.
        // Failures remain visible in logs/monitoring; they do NOT spam admins.
        if ($created->isNotEmpty()) {
            $admins = User::query()->whereIn('role', ['admin', 'superadmin'])->get();
            foreach ($created->unique('id') as $invoice) {
                $vaCount = ($invoice->salaries ?? collect())->pluck('employee_id')->filter()->unique()->count();
                $periodEnd = Carbon::parse($invoice->period_end)->toDateString();
                $notifier->send(
                    $admins,
                    'Monthly invoice generated',
                    "Automatic monthly invoice {$invoice->invoice_number} was generated for billing cycle ending {$periodEnd} "
                        ."({$vaCount} VA(s), total $".number_format((float) $invoice->total_amount, 2).').',
                    'InvoiceMonthlyRun',
                    '/admin/invoices',
                    [
                        'invoice_ids' => [$invoice->id],
                        'generated_count' => 1,
                        'billed_va_count' => $vaCount,
                        'total_amount' => round((float) $invoice->total_amount, 2),
                        'billing_cycle_end' => $periodEnd,
                    ],
                    true,
                    'invoice-monthly-created:'.$invoice->id
                );
            }
        }

        $scanned = $manualRun ? 'all clients' : $clients->count().' client batch';
        $this->info("Invoice run complete ({$scanned}): {$created->count()} created, {$noBillableAttendance} without billable attendance, {$notDue} not due/waiting, {$failed} failed.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function billingWindowCouldBeActive(Carbon $referenceNow, Carbon $nowUtc): bool
    {
        $openStart = BillingCycle::openStart($referenceNow);
        $openEnd = BillingCycle::endOf($openStart)->toDateString();
        [$completedStart, $completedEnd] = BillingCycle::latestCompleted($referenceNow);

        return $this->periodCouldBeActive($openEnd, $nowUtc)
            || $this->periodCouldBeActive($completedEnd, $nowUtc);
    }

    private function periodCouldBeActive(string $periodEnd, Carbon $nowUtc): bool
    {
        // Very broad UTC envelope covering all IANA offsets, overnight shifts and
        // the seven-day recovery policy. It intentionally errs on the safe side.
        $earliest = Carbon::parse($periodEnd.' 00:00:00', 'UTC')->subDay();
        $latest = Carbon::parse($periodEnd.' 23:59:59', 'UTC')->addDays(self::RECOVERY_DAYS + 2);
        return $nowUtc->gte($earliest) && $nowUtc->lte($latest);
    }

    private function periodDueForClientFromMaps(
        Client $client,
        Carbon $effectiveNowUtc,
        string $openStart,
        string $openEnd,
        string $completedStart,
        string $completedEnd,
        $openDueMap,
        $completedDueMap
    ): array {
        $openDueAt = $openDueMap->get($client->id);
        if ($openDueAt && $this->isDueOrRecoveryWindow($effectiveNowUtc, $openDueAt)) {
            return [$openStart, $openEnd];
        }

        $completedDueAt = $completedDueMap->get($client->id);
        if ($completedDueAt && $this->isDueOrRecoveryWindow($effectiveNowUtc, $completedDueAt)) {
            return [$completedStart, $completedEnd];
        }

        return [null, null];
    }

    private function periodDueForClient(Client $client, Carbon $referenceNow, Carbon $effectiveNowUtc, InvoiceAutoGenerationSchedule $autoSchedule): array
    {
        if ($this->option('force') || $this->option('date')) {
            return BillingCycle::latestCompleted($referenceNow);
        }

        // First evaluate the cycle that ends on the current open cycle's final day.
        // This permits one Doctor to generate as soon as their own final 25th shift
        // finishes without waiting for another Doctor in a later timezone.
        $openStart = BillingCycle::openStart($referenceNow);
        $openEnd = BillingCycle::endOf($openStart)->toDateString();
        $openDueAt = $autoSchedule->dueAtForClient($client, $openEnd);

        if ($this->isDueOrRecoveryWindow($effectiveNowUtc, $openDueAt)) {
            return [$openStart->toDateString(), $openEnd];
        }

        // If the open cycle is not due, allow the most recently completed cycle to
        // be picked up for seven days. This safely recovers from a missed cron/server
        // outage without leaving the command active for the entire following month.
        [$completedStart, $completedEnd] = BillingCycle::latestCompleted($referenceNow);
        $completedDueAt = $autoSchedule->dueAtForClient($client, $completedEnd);
        if ($this->isDueOrRecoveryWindow($effectiveNowUtc, $completedDueAt)) {
            return [$completedStart, $completedEnd];
        }

        return [null, null];
    }

    private function isDueOrRecoveryWindow(Carbon $nowUtc, Carbon $dueAtUtc): bool
    {
        if ($nowUtc->lt($dueAtUtc)) {
            return false;
        }

        // Retry continuously for a few hours so late checkout / auto-checkout can
        // settle naturally without missing the billing close.
        if ($nowUtc->lt($dueAtUtc->copy()->addHours(self::INITIAL_RETRY_HOURS))) {
            return true;
        }

        // After the initial window, retry briefly once per day for a week. This
        // recovers from a longer cron/server outage without scanning every Doctor
        // every minute for seven full days.
        for ($day = 1; $day <= self::RECOVERY_DAYS; $day++) {
            $windowStart = $dueAtUtc->copy()->addDays($day);
            $windowEnd = $windowStart->copy()->addMinutes(self::DAILY_RECOVERY_MINUTES);
            if ($nowUtc->gte($windowStart) && $nowUtc->lt($windowEnd)) {
                return true;
            }
        }

        return false;
    }
}
