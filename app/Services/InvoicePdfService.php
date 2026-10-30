<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ClientEmployeeRate;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
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
        $invoice->load([
            'client.service',
            'client.contractType',
            'salaries.employee',
            'adjustments.employee:id,first_name,last_name,employee_code',
        ]);

        $employeeIds = $invoice->salaries->pluck('employee_id')->filter()->unique()->values();
        $attendanceByEmployee = collect();
        if ($employeeIds->isNotEmpty()) {
            [$startUtc, $endUtc] = $this->timezones->utcBoundsForLocalDates(
                $invoice->period_start->toDateString(),
                $invoice->period_end->toDateString(),
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
                $salary->employee->setRelation('attendances', $attendanceByEmployee->get($salary->employee_id, collect()));
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

        $rates = ClientEmployeeRate::where('client_id', $invoice->client_id)->get()->keyBy('employee_id');
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
}
