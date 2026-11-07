<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Services\BillingCycle;
use App\Services\ClientTimezoneService;
use App\Services\InvoiceGenerator;
use App\Services\InvoiceAutoGenerationSchedule;
use App\Services\OperationalNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenerateMonthlyInvoices extends Command
{
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

        foreach (Client::query()->orderBy('id')->cursor() as $client) {
            try {
                [$periodStart, $periodEnd] = $this->periodDueForClient($client, $referenceNow, $effectiveNowUtc, $autoSchedule);

                if (!$periodStart || !$periodEnd) {
                    $notDue++;
                    continue;
                }

                if (!$this->option('force') && !$this->option('date')) {
                    $openAttendance = $autoSchedule->openAttendanceCountForClient($client->id, $periodEnd);
                    if ($openAttendance > 0) {
                        $notDue++;
                        continue;
                    }
                }

                $invoices = $generator->generateMany($client, $periodStart, $periodEnd, true);
                if ($invoices->isNotEmpty()) {
                    if (Schema::hasTable('invoice_generation_failures')) {
                        DB::table('invoice_generation_failures')->where('client_id', $client->id)->where('billing_month', Carbon::parse($periodEnd)->format('Y-m'))->whereNull('resolved_at')->update(['resolved_at' => now(), 'updated_at' => now()]);
                    }
                    foreach ($invoices as $invoice) {
                        $created->push($invoice->loadMissing('salaries'));
                    }
                } else {
                    $noBillableAttendance++;
                }
            } catch (\Throwable $e) {
                $failed++;
                if (Schema::hasTable('invoice_generation_failures')) {
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

        if ($created->isNotEmpty() || $failed > 0) {
            $invoiceCount = $created->count();
            $vaCount = $created
                ->flatMap(fn ($invoice) => $invoice->salaries ?? collect())
                ->pluck('employee_id')->filter()->unique()->count();
            $totalAmount = round((float) $created->sum('total_amount'), 2);

            $message = "Monthly invoice run: {$invoiceCount} draft invoice(s), {$vaCount} VA(s), total $"
                .number_format($totalAmount, 2)
                .". {$noBillableAttendance} client(s) had no completed unbilled attendance"
                .($notDue ? ", {$notDue} Doctor(s) still waiting for their cycle-end shift/checkout" : '')
                .($failed ? ", {$failed} failed" : '').'.';

            $admins = User::query()->whereIn('role', ['admin', 'superadmin'])->get();
            if ($admins->isNotEmpty()) {
                $notifier->send(
                    $admins,
                    $failed ? 'Monthly invoice run completed with warnings' : 'Monthly invoices generated',
                    $message,
                    'InvoiceMonthlyRun',
                    '/admin/invoices',
                    [
                        'invoice_ids' => $created->pluck('id')->all(),
                        'generated_count' => $invoiceCount,
                        'billed_va_count' => $vaCount,
                        'total_amount' => $totalAmount,
                        'failed_count' => $failed,
                    ]
                );
            }
        }

        $this->info("Invoice run complete: {$created->count()} created, {$noBillableAttendance} without billable attendance, {$notDue} not due/waiting, {$failed} failed.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
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

        if ($effectiveNowUtc->gte($openDueAt) && $effectiveNowUtc->lt($openDueAt->copy()->addDay())) {
            return [$openStart->toDateString(), $openEnd];
        }

        // If the open cycle is not due, allow the most recently completed cycle to
        // be picked up during the same 24-hour recovery window.
        [$completedStart, $completedEnd] = BillingCycle::latestCompleted($referenceNow);
        $completedDueAt = $autoSchedule->dueAtForClient($client, $completedEnd);
        if ($effectiveNowUtc->gte($completedDueAt) && $effectiveNowUtc->lt($completedDueAt->copy()->addDay())) {
            return [$completedStart, $completedEnd];
        }

        return [null, null];
    }
}
