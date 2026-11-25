<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\ClientEmployeeRate;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InvoiceDraftSyncService
{
    public function __construct(private ClientTimezoneService $timezones, private InvoiceAttendanceScope $attendanceScope)
    {
    }

    /**
     * Recalculate any editable Doctor invoice affected by an attendance change.
     * Finalized/sent invoices are intentionally excluded.
     */
    public function syncForAttendance(Attendance $attendance, ?string $previousLocalDate = null): void
    {
        if (!$attendance->client_id || !$attendance->employee_id) {
            return;
        }

        $client = Client::find($attendance->client_id);
        if (!$client) {
            return;
        }

        $currentLocalDate = $attendance->clock_in
            ? $this->timezones->localDate($attendance->clock_in, $client)
            : optional($attendance->date)->toDateString();

        $dates = collect([$previousLocalDate, $currentLocalDate])
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return;
        }

        $invoiceQuery = Invoice::query()
            ->where('client_id', $attendance->client_id)
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', 'draft');
            });

        if (Schema::hasColumn('invoices', 'sent_at')) {
            $invoiceQuery->whereNull('sent_at');
        }

        $invoiceIds = $invoiceQuery
            ->whereHas('salaries', function ($salaryQuery) use ($attendance, $dates) {
                $salaryQuery->where('employee_id', $attendance->employee_id)
                    ->where(function ($dateQuery) use ($dates) {
                        foreach ($dates as $date) {
                            $dateQuery->orWhere(function ($range) use ($date) {
                                $range->whereDate('period_start', '<=', $date)
                                    ->whereDate('period_end', '>=', $date);
                            });
                        }
                    });
            })
            ->pluck('id');

        foreach ($invoiceIds as $invoiceId) {
            $this->recalculateInvoice((int) $invoiceId);
        }
    }

    public function recalculateInvoice(int $invoiceId): void
    {
        DB::transaction(function () use ($invoiceId) {
            $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
            if (!$invoice) {
                return;
            }

            $status = $invoice->status ?? 'draft';
            if (!in_array($status, ['draft', ''], true) || (Schema::hasColumn('invoices', 'sent_at') && $invoice->sent_at)) {
                return;
            }

            $client = Client::find($invoice->client_id);
            if (!$client) {
                return;
            }

            $salaries = $invoice->salaries()->orderBy('id')->get();
            if ($salaries->isEmpty()) {
                $adjustmentTotal = 0.0;
                if (Schema::hasTable('invoice_adjustments')) {
                    foreach ($invoice->adjustments as $adjustment) {
                        $amount = (float) $adjustment->amount;
                        $adjustmentTotal += $adjustment->direction === 'deduction' ? -$amount : $amount;
                    }
                }
                $invoice->update(['total_hours' => 0, 'total_amount' => max(0, round($adjustmentTotal, 2))]);
                return;
            }

            $rates = ClientEmployeeRate::query()
                ->where('client_id', $client->id)
                ->whereIn('employee_id', $salaries->pluck('employee_id'))
                ->pluck('per_hour_rate', 'employee_id');

            $scheduleByEmployee = $this->attendanceScope->schedules($client, $salaries->pluck('employee_id'));
            foreach ($salaries as $salary) {
                [$startUtc, $endUtc] = $this->timezones->utcBoundsForLocalDates(
                    Carbon::parse($salary->period_start)->toDateString(),
                    Carbon::parse($salary->period_end)->toDateString(),
                    $client
                );

                $records = Attendance::query()
                    ->where('client_id', $client->id)
                    ->where('employee_id', $salary->employee_id)
                    ->whereBetween('clock_in', [$startUtc, $endUtc])
                    ->whereNotNull('clock_out')
                    ->get();

                $records = $this->attendanceScope->filter(
                    $records, $client, $scheduleByEmployee->get($salary->employee_id, collect())
                );

                $regularMinutes = (int) $records->sum('total_minutes');
                $approvedOvertime = (int) $records->sum(function (Attendance $record) {
                    if (($record->overtime_status ?? 'none') !== 'approved') {
                        return 0;
                    }
                    return min(
                        max(0, (int) ($record->approved_overtime_minutes ?? 0)),
                        max(0, (int) ($record->overtime ?? 0))
                    );
                });

                $rate = (float) ($rates->get($salary->employee_id) ?? $client->per_hour_charges ?? 0);
                $overtimePayment = (float) $records->sum(function (Attendance $record) use ($rate) {
                    if (($record->overtime_status ?? 'none') !== 'approved') {
                        return 0;
                    }
                    $minutes = min(
                        max(0, (int) ($record->approved_overtime_minutes ?? 0)),
                        max(0, (int) ($record->overtime ?? 0))
                    );
                    return $record->overtime_amount !== null
                        ? (float) $record->overtime_amount
                        : ($minutes / 60 * $rate);
                });

                $salary->update([
                    'total_hours' => round(($regularMinutes + $approvedOvertime) / 60, 2),
                    'total_late' => (int) $records->sum('late_minutes'),
                    'total_overtime' => $approvedOvertime,
                    'salary_amount' => round(($regularMinutes / 60 * $rate) + $overtimePayment, 2),
                ]);
            }

            $salaryRows = $invoice->salaries()->get();
            $adjustmentTotal = 0.0;
            if (Schema::hasTable('invoice_adjustments')) {
                $invoice->adjustments()->get()->each(function ($adjustment) use (&$adjustmentTotal) {
                    $amount = (float) $adjustment->amount;
                    $adjustmentTotal += $adjustment->direction === 'deduction' ? -$amount : $amount;
                });
            }

            if (Schema::hasColumn('employee_salaries', 'pdf_snapshot')) {
                $invoice->salaries()->update(['pdf_snapshot' => null]);
            }
            $invoice->update([
                'total_hours' => round((float) $salaryRows->sum('total_hours'), 2),
                'total_amount' => max(0, round((float) $salaryRows->sum('salary_amount') + $adjustmentTotal, 2)),
                'pdf_snapshot' => null,
                'snapshot_at' => null,
            ]);
        }, 3);
    }
}
