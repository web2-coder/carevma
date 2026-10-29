<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\EmployeeClientSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class AttendanceBillingService
{
    public function __construct(private ClientTimezoneService $timezones)
    {
    }

    public function finalize(Attendance $attendance, Carbon $clockOutUtc): Attendance
    {
        $attendance->loadMissing(['breaks', 'client', 'schedule']);
        $clockOutUtc = $clockOutUtc->copy()->utc();

        $openBreak = $attendance->breaks->whereNull('end_time')->sortByDesc('id')->first();
        if ($openBreak) {
            $startUtc = $openBreak->start_time instanceof Carbon
                ? $openBreak->start_time->copy()->utc()
                : Carbon::parse($openBreak->start_time, 'UTC')->utc();
            $seconds = max(0, $clockOutUtc->timestamp - $startUtc->timestamp);
            $openBreak->update(['end_time' => $clockOutUtc]);
            $attendance->break_taken = (int) ($attendance->break_taken ?? 0) + $seconds;
        }

        $attendance->clock_out = $clockOutUtc;
        return $this->recalculate($attendance);
    }

    public function recalculate(Attendance $attendance): Attendance
    {
        if (!$attendance->clock_in || !$attendance->clock_out) {
            return $attendance;
        }

        $attendance->loadMissing(['client', 'schedule']);
        $timezone = $this->timezones->forAttendance($attendance, false);
        $clockIn = $attendance->clock_in->copy()->setTimezone($timezone)->startOfMinute();
        $clockOut = $attendance->clock_out->copy()->setTimezone($timezone)->startOfMinute();
        $regularStart = $clockIn->copy();
        $regularEnd = $clockOut->copy();
        $shiftStart = null;
        $shiftEnd = null;
        $overtime = 0;
        $late = 0;

        if ($attendance->shift_start_snapshot && $attendance->shift_end_snapshot) {
            $shiftStart = $attendance->shift_start_snapshot->copy()->setTimezone($timezone)->startOfMinute();
            $shiftEnd = $attendance->shift_end_snapshot->copy()->setTimezone($timezone)->startOfMinute();
        } elseif ($attendance->schedule) {
            $schedule = $attendance->schedule;
            $date = $attendance->date?->toDateString() ?: $clockIn->toDateString();
            $shiftStart = Carbon::parse($date.' '.$schedule->start_time, $timezone)->startOfMinute();
            $shiftEnd = Carbon::parse($date.' '.$schedule->end_time, $timezone)->startOfMinute();
            if ($shiftEnd->lte($shiftStart)) {
                $shiftEnd->addDay();
            }
        }

        if ($shiftStart && $shiftEnd) {
            if ($regularStart->lt($shiftStart)) {
                $regularStart = $shiftStart->copy();
            }
            if ($regularStart->gt($shiftStart)) {
                $late = (int) $shiftStart->diffInMinutes($regularStart);
            }
            if ($regularEnd->gt($shiftEnd)) {
                $overtime = (int) $shiftEnd->diffInMinutes($regularEnd);
                $regularEnd = $shiftEnd->copy();
            }
        }

        $breakMinutes = (int) floor(max(0, (int) ($attendance->break_taken ?? 0)) / 60);
        $workedInsideShift = max(0, (int) $regularStart->diffInMinutes($regularEnd) - $breakMinutes);

        // A scheduled break is part of the shift length but is never billable.
        // Example: 08:00-17:00 (9h) with a 1h break => maximum regular billing is 8h,
        // even if the VA clocks in early, clocks out late, or skips the break.
        if ($shiftStart && $shiftEnd) {
            $scheduledMinutes = max(0, (int) $shiftStart->diffInMinutes($shiftEnd));
            $breakAllowanceMinutes = $this->hmsToMinutes($attendance->break_limit);
            $regularCap = max(0, $scheduledMinutes - $breakAllowanceMinutes);
            $workedInsideShift = min($workedInsideShift, $regularCap);
        }

        $attendance->total_minutes = $workedInsideShift;
        $attendance->is_late = $late > 0;
        $attendance->late_minutes = $late;
        $attendance->overtime = $overtime;

        if (Schema::hasColumn('attendances', 'overtime_status')) {
            // Extra time is not billable until the VA explicitly requests review and HR/Admin approves it.
            $attendance->overtime_status = $overtime > 0 ? 'review_required' : 'none';
            $attendance->approved_overtime_minutes = 0;
            $attendance->overtime_amount = null;
            $attendance->overtime_approved_by = null;
            $attendance->overtime_approved_at = null;
        }

        $attendance->save();
        return $attendance->fresh(['client', 'schedule', 'breaks']);
    }

    private function hmsToMinutes(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $parts = array_map('intval', explode(':', (string) $value));
        return max(0, (($parts[0] ?? 0) * 60) + ($parts[1] ?? 0));
    }
}
