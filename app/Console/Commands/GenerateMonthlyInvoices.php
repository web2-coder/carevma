<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
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
        $skipped = 0;
        $noBillableAttendance = 0;
        $failed = 0;
        $notDue = 0;

        foreach (Client::query()->orderBy('id')->cursor() as $client) {
            $zone = $timezones->forClient($client);
            $localNow = $this->option('date')
                ? Carbon::parse($this->option('date').' 00:15:00', $zone)
                : $timezones->now($client);

            // Automatic generation is evaluated in every client's own timezone.
            // The global scheduler may run every minute; only a client whose local
            // clock is 26th 00:15 enters the billing close.
            if (!$this->option('force') && !$this->option('date')) {
                if ($localNow->day !== 26 || $localNow->format('H:i') !== '00:15') {
                    $notDue++;
                    continue;
                }
            }

            // Always use a canonical 26th -> 25th cycle, even for --force/testing.
            // On/after the 26th the latest completed cycle ends on this month's 25th;
            // before the 26th it ends on the previous month's 25th.
            $cycleEnd = $localNow->copy()->day(25);
            if ($localNow->day < 26) {
                $cycleEnd->subMonthNoOverflow()->day(25);
            }
            $periodEnd = $cycleEnd->toDateString();
            $periodStart = $cycleEnd->copy()->subMonthNoOverflow()->day(26)->toDateString();

            if (Invoice::where('client_id', $client->id)
                ->whereDate('period_start', $periodStart)
                ->whereDate('period_end', $periodEnd)
                ->exists()) {
                $skipped++;
                continue;
            }

            try {
                $invoice = $generator->generate($client, $periodStart, $periodEnd, true);
                if ($invoice) {
                    $created->push($invoice->loadMissing('salaries'));
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
                .". {$skipped} duplicate(s) skipped, {$noBillableAttendance} client(s) had no completed unbilled attendance"
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

        $this->info("Invoice run complete: {$created->count()} created, {$skipped} duplicate(s), {$noBillableAttendance} without billable attendance, {$notDue} not due, {$failed} failed.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
