<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ClientEmployeeRate;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\Service;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class InvoicePdfService
{
    public function __construct(
        private ClientTimezoneService $timezones,
        private InvoiceSnapshotService $snapshots,
    ) {
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

        // Existing finalized invoices from before this patch are snapshotted on
        // their first PDF access. Drafts remain live/editable until issued or sent.
        if ($this->shouldFreeze($invoice) && !$this->hasFrozenSnapshot($invoice)) {
            $invoice = $this->snapshots->freeze($invoice);
            $invoice->load(['client.service', 'client.contractType', 'salaries.employee', 'adjustments.employee']);
        }

        if ($this->hasFrozenSnapshot($invoice)) {
            return $this->makeFromSnapshot($invoice);
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
            // Ask the database for one first clock-in per VA instead of loading
            // every historical attendance row into PHP just to find the minimum.
            $firstCheckInDateByEmployee = $firstRows
                ->select('employee_id')
                ->selectRaw('MIN(clock_in) AS first_clock_in')
                ->groupBy('employee_id')
                ->get()
                ->mapWithKeys(function ($row) use ($invoice) {
                    return [$row->employee_id => $row->first_clock_in
                        ? $this->timezones->localDate($row->first_clock_in, $invoice->client)
                        : null];
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
            ? ClientEmployeeRate::where('client_id', $invoice->client_id)
                ->whereIn('employee_id', $employeeIds)
                ->get()
                ->keyBy('employee_id')
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



    private function shouldFreeze(Invoice $invoice): bool
    {
        return in_array($invoice->status ?? 'draft', ['issued', 'paid', 'void'], true)
            || (Schema::hasColumn('invoices', 'sent_at') && (bool) $invoice->sent_at);
    }

    private function hasFrozenSnapshot(Invoice $invoice): bool
    {
        if (!Schema::hasColumn('invoices', 'pdf_snapshot') || !Schema::hasColumn('invoices', 'snapshot_at') || !Schema::hasColumn('employee_salaries', 'pdf_snapshot')) {
            return false;
        }

        if (!$invoice->snapshot_at || !is_array($invoice->pdf_snapshot)) {
            return false;
        }

        return $invoice->salaries->every(fn ($salary) => is_array($salary->pdf_snapshot));
    }

    private function makeFromSnapshot(Invoice $invoice)
    {
        $clientSnapshot = (array) data_get($invoice->pdf_snapshot, 'client', []);
        $client = $invoice->client;
        if ($client && $clientSnapshot) {
            $client->forceFill(array_filter([
                'name' => $clientSnapshot['name'] ?? null,
                'business_name' => $clientSnapshot['business_name'] ?? null,
                'email' => $clientSnapshot['email'] ?? null,
                'address' => $clientSnapshot['address'] ?? null,
                'timezone' => $clientSnapshot['timezone'] ?? null,
            ], fn ($value) => $value !== null));

            $service = new Service(['name' => $clientSnapshot['service_name'] ?? 'Medical VA Service']);
            $contractType = new ContractType(['name' => $clientSnapshot['contract_type_name'] ?? 'Not specified']);
            $client->setRelation('service', $service);
            $client->setRelation('contractType', $contractType);
        }

        $rates = collect();
        $scheduleByEmployee = collect();
        foreach ($invoice->salaries as $salary) {
            $snapshot = (array) $salary->pdf_snapshot;
            $employeeData = (array) ($snapshot['employee'] ?? []);
            $employeeData['id'] = $employeeData['id'] ?? $salary->employee_id;
            $employee = (new Employee())->newFromBuilder($employeeData);

            $attendances = collect($snapshot['attendance'] ?? [])->map(function ($row) {
                return (new Attendance())->newFromBuilder((array) $row);
            });
            $employee->setRelation('attendances', $attendances);
            $salary->setRelation('employee', $employee);

            $rates->put($salary->employee_id, (object) [
                'per_hour_rate' => (float) ($snapshot['rate'] ?? 0),
            ]);
            $scheduleByEmployee->put(
                $salary->employee_id,
                collect($snapshot['schedules'] ?? [])->map(fn ($row) => (object) $row)
            );
        }

        $clientTimezone = $clientSnapshot['timezone'] ?? $this->timezones->forClient($client);

        return Pdf::loadView('invoices.invoice', [
            'invoice' => $invoice,
            'client' => $client,
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
