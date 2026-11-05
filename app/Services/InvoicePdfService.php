<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ClientEmployeeRate;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class InvoicePdfService
{
    public function __construct(private ClientTimezoneService $timezones)
    {
    }

    public function make(Invoice $invoice)
    {
        $invoice = $invoice->fresh();
        $invoice->load(['client.service', 'client.contractType']);

        // Live-safe PDF hydration: older CareVMA databases may not yet contain
        // one of the newer invoice support tables/columns. The PDF must still
        // render instead of throwing a 500 while reading an optional relation.
        if (Schema::hasTable('employee_salaries') && Schema::hasColumn('employee_salaries', 'invoice_id')) {
            $invoice->load('salaries.employee');
        } else {
            $invoice->setRelation('salaries', collect());
        }

        if (Schema::hasTable('invoice_adjustments') && Schema::hasColumn('invoice_adjustments', 'invoice_id')) {
            $invoice->load('adjustments.employee:id,first_name,last_name,employee_code');
        } else {
            $invoice->setRelation('adjustments', collect());
        }

        $invoiceStart = $this->dateString($invoice->period_start);
        $invoiceEnd = $this->dateString($invoice->period_end);

        if (! $invoiceStart || ! $invoiceEnd) {
            throw new \RuntimeException('Invoice period start/end is missing or invalid.');
        }

        $employeeIds = $invoice->salaries->pluck('employee_id')->filter()->unique()->values();

        // PDF hard guard for legacy/manual records: never render dates before a
        // VA's first actual check-in with this Doctor, even if an older start date
        // is stored or was manually selected.
        $firstCheckInDateByEmployee = collect();
        if ($employeeIds->isNotEmpty()) {
            $firstRows = Attendance::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereNotNull('clock_in');
            if (Schema::hasColumn('attendances', 'client_id')) {
                $firstRows->where('client_id', $invoice->client_id);
            }
            $firstCheckInDateByEmployee = $firstRows
                ->orderBy('clock_in')
                ->get(['employee_id', 'clock_in'])
                ->groupBy('employee_id')
                ->map(function ($rows) use ($invoice) {
                    $first = $rows->first();
                    return $first?->clock_in
                        ? $this->timezones->localDate($first->clock_in, $invoice->client)
                        : null;
                })
                ->filter();

            $effectiveStarts = $invoice->salaries->map(function ($salary) use ($invoiceStart, $firstCheckInDateByEmployee) {
                $storedStart = $this->dateString($salary->period_start) ?: $invoiceStart;
                $firstCheckInDate = $firstCheckInDateByEmployee->get($salary->employee_id);
                return $firstCheckInDate && $firstCheckInDate > $storedStart
                    ? $firstCheckInDate
                    : $storedStart;
            })->filter();

            if ($effectiveStarts->isNotEmpty()) {
                $invoiceStart = $effectiveStarts->min();
                $invoice->period_start = $invoiceStart;
            }
        }

        $attendanceByEmployee = collect();
        if ($employeeIds->isNotEmpty()) {
            [$startUtc, $endUtc] = $this->timezones->utcBoundsForLocalDates(
                $invoiceStart,
                $invoiceEnd,
                $invoice->client
            );
            $query = Attendance::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('clock_in', [$startUtc, $endUtc])
                ->whereNotNull('clock_out')
                ->orderBy('clock_in');
            if (Schema::hasColumn('attendances', 'client_id')) {
                $query->where('client_id', $invoice->client_id);
            }
            $attendanceByEmployee = $query->get()->groupBy('employee_id');
        }

        foreach ($invoice->salaries as $salary) {
            if ($salary->employee) {
                $salaryStart = $this->dateString($salary->period_start) ?: $invoiceStart;
                $firstCheckInDate = $firstCheckInDateByEmployee->get($salary->employee_id);
                if ($firstCheckInDate && $firstCheckInDate > $salaryStart) {
                    $salaryStart = $firstCheckInDate;
                }
                // Keep the Blade attendance cursor and item From date aligned with
                // the effective first-check-in start.
                $salary->period_start = $salaryStart;
                $salaryEnd = $this->dateString($salary->period_end) ?: $invoiceEnd;
                $rows = $attendanceByEmployee->get($salary->employee_id, collect())->filter(function (Attendance $attendance) use ($invoice, $salaryStart, $salaryEnd) {
                    if (! $attendance->clock_in) {
                        return false;
                    }
                    $localDate = $this->timezones->localDate($attendance->clock_in, $invoice->client);
                    return $localDate >= $salaryStart && $localDate <= $salaryEnd;
                })->values();
                $salary->employee->setRelation('attendances', $rows);
            }
        }

        $scheduleByEmployee = collect();
        if ($employeeIds->isNotEmpty() && Schema::hasTable('employee_client_schedules')) {
            $scheduleQuery = DB::table('employee_client_schedules')
                ->where('client_id', $invoice->client_id)
                ->whereIn('employee_id', $employeeIds);
            if (Schema::hasColumn('employee_client_schedules', 'enabled')) {
                $scheduleQuery->where('enabled', true);
            }
            $scheduleByEmployee = $scheduleQuery
                ->orderBy('employee_id')
                ->orderBy('start_time')
                ->get()
                ->groupBy('employee_id');
        }

        $rates = Schema::hasTable('client_employee_rates')
            ? ClientEmployeeRate::where('client_id', $invoice->client_id)->get()->keyBy('employee_id')
            : collect();
        $clientTimezone = $this->timezones->forClient($invoice->client);

        return Pdf::loadView('invoices.invoice', [
            'invoice' => $invoice,
            'client' => $invoice->client,
            'salaries' => $invoice->salaries,
            'clientEmployeeRates' => $rates,
            'clientTimezone' => $clientTimezone,
            'scheduleByEmployee' => $scheduleByEmployee,
        ]);
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
