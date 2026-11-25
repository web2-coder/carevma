<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ClientEmployeeRate;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InvoiceSnapshotService
{
    public function __construct(private ClientTimezoneService $timezones, private InvoiceAttendanceScope $attendanceScope)
    {
    }

    /**
     * Freeze the data used to render an issued/sent/paid invoice PDF.
     * Snapshot columns are nullable and guarded for rolling deployments.
     */
    public function freeze(Invoice $invoice): Invoice
    {
        if (!Schema::hasColumn('invoices', 'pdf_snapshot') || !Schema::hasColumn('invoices', 'snapshot_at') || !Schema::hasColumn('employee_salaries', 'pdf_snapshot')) {
            return $invoice->fresh();
        }

        return DB::transaction(function () use ($invoice) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $locked->load(['client.service', 'client.contractType', 'salaries.employee']);

            $salaryRows = $locked->salaries;
            $rates = ClientEmployeeRate::query()
                ->where('client_id', $locked->client_id)
                ->whereIn('employee_id', $salaryRows->pluck('employee_id'))
                ->pluck('per_hour_rate', 'employee_id');

            // Keep current and historical Doctor/VA schedules available in the PDF.
            $allSchedules = $this->attendanceScope->schedules($locked->client, $salaryRows->pluck('employee_id'));
            $scheduleByEmployee = $allSchedules;

            foreach ($salaryRows as $salary) {
                [$startUtc, $endUtc] = $this->timezones->utcBoundsForLocalDates(
                    Carbon::parse($salary->period_start)->toDateString(),
                    Carbon::parse($salary->period_end)->toDateString(),
                    $locked->client
                );

                $attendance = Attendance::query()
                    ->where('client_id', $locked->client_id)
                    ->where('employee_id', $salary->employee_id)
                    ->whereBetween('clock_in', [$startUtc, $endUtc])
                    ->whereNotNull('clock_out')
                    ->orderBy('clock_in')
                    ->get();

                $attendance = $this->attendanceScope->filter(
                    $attendance, $locked->client, $allSchedules->get($salary->employee_id, collect())
                )->map(fn (Attendance $row) => [
                        'id' => $row->id,
                        'employee_id' => $row->employee_id,
                        'client_id' => $row->client_id,
                        'schedule_id' => $row->schedule_id,
                        'date' => optional($row->date)->toDateString() ?: $row->getRawOriginal('date'),
                        'timezone_snapshot' => $row->timezone_snapshot,
                        'shift_start_snapshot' => $row->getRawOriginal('shift_start_snapshot'),
                        'shift_end_snapshot' => $row->getRawOriginal('shift_end_snapshot'),
                        'clock_in' => $row->getRawOriginal('clock_in'),
                        'clock_out' => $row->getRawOriginal('clock_out'),
                        'break_limit' => $row->break_limit,
                        'break_taken' => (int) ($row->break_taken ?? 0),
                        'total_minutes' => (int) ($row->total_minutes ?? 0),
                        'overtime' => (int) ($row->overtime ?? 0),
                        'overtime_status' => $row->overtime_status,
                        'approved_overtime_minutes' => (int) ($row->approved_overtime_minutes ?? 0),
                        'overtime_amount' => $row->overtime_amount,
                        'is_late' => (bool) ($row->is_late ?? false),
                        'late_minutes' => (int) ($row->late_minutes ?? 0),
                    ])->values()->all();

                $schedules = $scheduleByEmployee->get($salary->employee_id, collect())
                    ->map(fn ($schedule) => [
                        'id' => $schedule->id ?? null,
                        'employee_id' => $schedule->employee_id ?? null,
                        'client_id' => $schedule->client_id ?? null,
                        'weekday' => $schedule->weekday ?? null,
                        'schedule_date' => $schedule->schedule_date ?? null,
                        'start_time' => $schedule->start_time ?? null,
                        'end_time' => $schedule->end_time ?? null,
                        'break_allowed_hours' => $schedule->break_allowed_hours ?? null,
                        'timezone' => $schedule->timezone ?? null,
                        'enabled' => $schedule->enabled ?? null,
                        'repeat' => $schedule->repeat ?? null,
                    ])->values()->all();

                $salary->forceFill([
                    'pdf_snapshot' => [
                        'employee' => [
                            'id' => $salary->employee?->id,
                            'first_name' => $salary->employee?->first_name,
                            'last_name' => $salary->employee?->last_name,
                            'employee_code' => $salary->employee?->employee_code,
                        ],
                        'rate' => (float) ($rates->get($salary->employee_id) ?? $locked->client?->per_hour_charges ?? 0),
                        'attendance' => $attendance,
                        'schedules' => $schedules,
                    ],
                ])->save();
            }

            $client = $locked->client;
            $locked->forceFill([
                'pdf_snapshot' => [
                    'client' => [
                        'id' => $client?->id,
                        'name' => $client?->name,
                        'business_name' => $client?->business_name,
                        'email' => $client?->email,
                        'address' => $client?->address,
                        'timezone' => $client?->timezone,
                        'service_name' => $client?->service?->name,
                        'contract_type_name' => $client?->contractType?->name,
                    ],
                ],
                'snapshot_at' => now(),
            ])->save();

            return $locked->fresh(['client', 'salaries.employee', 'adjustments.employee']);
        }, 3);
    }

    /** Clear a transient snapshot only while an invoice is still an unsent draft. */
    public function clearDraftSnapshot(Invoice $invoice): void
    {
        if (!Schema::hasColumn('invoices', 'pdf_snapshot') || !Schema::hasColumn('invoices', 'snapshot_at') || !Schema::hasColumn('employee_salaries', 'pdf_snapshot')) {
            return;
        }

        $invoice = $invoice->fresh();
        if (!$invoice || !in_array($invoice->status ?? 'draft', ['draft', ''], true) || $invoice->sent_at) {
            return;
        }

        DB::transaction(function () use ($invoice) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (!in_array($locked->status ?? 'draft', ['draft', ''], true) || $locked->sent_at) {
                return;
            }
            $locked->salaries()->update(['pdf_snapshot' => null]);
            $locked->forceFill(['pdf_snapshot' => null, 'snapshot_at' => null])->save();
        });
    }
}
