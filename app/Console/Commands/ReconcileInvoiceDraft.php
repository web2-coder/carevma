<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Invoice;
use App\Services\ClientTimezoneService;
use App\Services\InvoiceAttendanceScope;
use App\Services\InvoiceDraftSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Explicit, backed-up correction of invalid salary rows in ONE unsent draft. */
class ReconcileInvoiceDraft extends Command
{
    protected $signature = 'carevma:invoice-draft-audit {invoice_id : One invoice ID} {--apply : Explicitly repair the unsent draft after backup}';
    protected $description = 'Preview invalid Doctor/VA invoice rows and optionally reconcile an unsent draft safely';

    public function handle(InvoiceAttendanceScope $scope, ClientTimezoneService $timezones, InvoiceDraftSyncService $sync): int
    {
        $invoiceId = (int) $this->argument('invoice_id');
        $invoice = Invoice::with(['client', 'salaries'])->find($invoiceId);
        if (!$invoice || !$invoice->client) {
            $this->error('Invoice or Doctor not found.');
            return self::FAILURE;
        }
        if (!in_array($invoice->status ?? 'draft', ['draft', ''], true) || $invoice->sent_at) {
            $this->error('Only unsent drafts may be reconciled. Issued, sent and paid invoices stay immutable.');
            return self::FAILURE;
        }

        $audit = function (Invoice $draft) use ($scope, $timezones): array {
            $schedules = $scope->schedules($draft->client, $draft->salaries->pluck('employee_id'));
            $result = [];
            foreach ($draft->salaries as $salary) {
                [$start, $end] = $timezones->utcBoundsForLocalDates(
                    Carbon::parse($salary->period_start)->toDateString(),
                    Carbon::parse($salary->period_end)->toDateString(), $draft->client
                );
                $records = Attendance::query()->where('client_id', $draft->client_id)
                    ->where('employee_id', $salary->employee_id)->whereBetween('clock_in', [$start, $end])
                    ->whereNotNull('clock_out')->get();
                $valid = $scope->filter($records, $draft->client, $schedules->get($salary->employee_id, collect()));
                $result[] = ['salary_id' => $salary->id, 'employee_id' => $salary->employee_id,
                    'valid_attendances' => $valid->count(), 'excluded_attendances' => $records->count() - $valid->count(),
                    'remove_from_draft' => $valid->isEmpty()];
            }
            return $result;
        };
        $report = $audit($invoice);
        $this->table(['Salary ID', 'VA ID', 'Valid attendance', 'Excluded attendance', 'Remove from draft'],
            array_map(fn ($r) => [$r['salary_id'], $r['employee_id'], $r['valid_attendances'], $r['excluded_attendances'], $r['remove_from_draft'] ? 'YES' : 'no'], $report));
        if (!$this->option('apply')) {
            $this->warn('DRY RUN: zero changes. Only use --apply after reviewing this report and backing up the database.');
            return self::SUCCESS;
        }

        // Back up all original line values before taking any corrective action.
        $backup = [
            'invoice' => $invoice->getAttributes(),
            'salaries' => $invoice->salaries->map(fn ($s) => $s->getAttributes())->all(),
            'report' => $report,
            'created_at' => now()->toIso8601String(),
        ];
        $relative = 'carevma-invoice-backups/invoice-'.$invoice->id.'-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)).'.json';
        if (!Storage::disk('local')->put($relative, json_encode($backup, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
            $this->error('Backup failed. No database change made.');
            return self::FAILURE;
        }
        $this->info('Local backup saved: storage/app/private/'.$relative.' (disk path depends on Laravel configuration)');

        DB::transaction(function () use ($invoiceId, $scope, $timezones) {
            $draft = Invoice::query()->with('client')->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            if (!in_array($draft->status ?? 'draft', ['draft', ''], true) || $draft->sent_at) {
                throw new \RuntimeException('Draft status changed during reconciliation. Aborted.');
            }
            $lines = $draft->salaries()->orderBy('id')->lockForUpdate()->get();
            $schedules = $scope->schedules($draft->client, $lines->pluck('employee_id'));
            foreach ($lines as $salary) {
                [$start, $end] = $timezones->utcBoundsForLocalDates(
                    Carbon::parse($salary->period_start)->toDateString(),
                    Carbon::parse($salary->period_end)->toDateString(), $draft->client
                );
                $records = Attendance::query()->where('client_id', $draft->client_id)
                    ->where('employee_id', $salary->employee_id)->whereBetween('clock_in', [$start, $end])
                    ->whereNotNull('clock_out')->get();
                if ($scope->filter($records, $draft->client, $schedules->get($salary->employee_id, collect()))->isEmpty()) {
                    // Never delete attendance, assignments, or finalized financial history.
                    // This removes only incorrect salary rows from the specified unsent draft,
                    // after the full original invoice lines have been backed up.
                    $salary->delete();
                }
            }
        }, 3);
        $sync->recalculateInvoice($invoiceId);
        $this->info('Draft reconciled. Verify invoice/PDF totals manually before issuance.');
        return self::SUCCESS;
    }
}
