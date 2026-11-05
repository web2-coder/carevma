<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BillingCycle;
use App\Services\ClientTimezoneService;
use App\Services\InvoiceGenerator;
use App\Services\OperationalNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'invoices:generate-monthly
        {--date= : Override each client local run date for testing (Y-m-d)}
        {--force : Run regardless of the client-local day/time gate}';

    protected $description = 'Generate duplicate-safe monthly draft invoices in each client/doctor timezone';

    public function handle(
        InvoiceGenerator $generator,
        OperationalNotifier $notifier,
        ClientTimezoneService $timezones,
    ): int {
        $created = collect();
        $noBillableAttendance = 0;
        $failed = 0;
        $notDue = 0;

        if (!BillingCycle::autoGenerate() && !$this->option('force')) {
            $this->info('Automatic invoice generation is switched off in Invoice Settings; nothing to do.');
            return self::SUCCESS;
        }

        foreach (Client::query()->orderBy('id')->cursor() as $client) {
            $zone = $timezones->forClient($client);
            $localNow = $this->option('date')
                ? Carbon::parse($this->option('date').' '.BillingCycle::generationTime().':00', $zone)
                : $timezones->now($client);

            // Automatic generation is evaluated in every client's own timezone, against the
            // cycle configured in Invoice Settings. The scheduler runs every minute; a client
            // enters the billing close once its local clock passes the generation time on the
            // cycle start day, and stays due for 24 hours. A window rather than one exact minute,
            // because a missed tick (cron skew, a restart, a stale lock) used to cost a whole
            // month's invoice silently. The period is derived from the date alone, so a later tick
            // computes the identical cycle and the generator's coverage check keeps it idempotent.
            if (!$this->option('force') && !$this->option('date')) {
                $dueFrom = BillingCycle::openStart($localNow)->setTimeFromTimeString(BillingCycle::generationTime());
                if ($localNow->lt($dueFrom) || $localNow->gte($dueFrom->copy()->addDay())) {
                    $notDue++;
                    continue;
                }
            }

            [$periodStart, $periodEnd] = BillingCycle::latestCompleted($localNow);

            // No exact-cycle skip here: the generator bills only VAs not yet on an invoice
            // for these dates, so a partial manual invoice does not strand the rest.
            try {
                $invoices = $generator->generateMany($client, $periodStart, $periodEnd, true);
                if ($invoices->isNotEmpty()) {
                    foreach ($invoices as $invoice) {
                        $created->push($invoice->loadMissing('salaries'));
                    }
                } else {
                    $noBillableAttendance++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Automatic invoice generation failed for client.', [
                    'client_id' => $client->id,
                    'client_timezone' => $zone,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
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
                .($failed ? ", {$failed} failed" : '').'. Each client was evaluated in its assigned timezone.';

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

        $this->info("Invoice run complete: {$created->count()} created, {$noBillableAttendance} without billable attendance, {$notDue} not due, {$failed} failed.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
