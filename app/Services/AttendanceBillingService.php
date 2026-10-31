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

        $attendance->loadMissing(['client', 'schedule', 'breaks']);
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

        // Split breaks by the window where they actually happened.
        // Regular work is only inside the Doctor shift. Anything after shift end is overtime.
        $regularBreakMinutes = 0;
        $overtimeBreakMinutes = 0;
        $closedBreaks = $attendance->breaks->whereNotNull('end_time');

        if ($closedBreaks->isNotEmpty() && $shiftStart && $shiftEnd) {
            foreach ($closedBreaks as $break) {
                $breakStart = $break->start_time->copy()->setTimezone($timezone)->startOfMinute();
                $breakEnd = $break->end_time->copy()->setTimezone($timezone)->startOfMinute();
                $regularBreakMinutes += $this->overlapMinutes($breakStart, $breakEnd, $regularStart, $regularEnd);
                if ($clockOut->gt($shiftEnd)) {
                    $overtimeBreakMinutes += $this->overlapMinutes($breakStart, $breakEnd, $shiftEnd, $clockOut);
                }
            }
        } else {
            // Backward-compatible fallback for old attendance rows that only store break_taken.
            $regularBreakMinutes = $breakMinutes;
        }

        $workedInsideShift = max(0, (int) $regularStart->diffInMinutes($regularEnd) - $regularBreakMinutes);
        if ($shiftEnd && $clockOut->gt($shiftEnd)) {
            $overtime = max(0, (int) $shiftEnd->diffInMinutes($clockOut) - $overtimeBreakMinutes);
        }

        // CareVMA billing rule:
        // regular payable time is the time actually covered inside the Doctor shift,
        // less the ASSIGNED break allowance (or a longer actual break, if taken).
        // The regular portion can never exceed the scheduled net shift duration.
        // Example: 17:00-19:00 with a 00:30 break allowance => 90 regular minutes.
        // If checkout is 19:25, the extra 25 minutes remain overtime and are only
        // added to payable time after HR/Admin approval.
        if ($shiftStart && $shiftEnd) {
            $scheduledMinutes = max(0, (int) $shiftStart->diffInMinutes($shiftEnd));
            $allowedBreakMinutes = $this->hmsToMinutes($attendance->break_limit);
            if ($allowedBreakMinutes <= 0 && $attendance->schedule) {
                $raw = number_format(max(0, (float) ($attendance->schedule->break_allowed_hours ?? 0)), 2, '.', '');
                [$hours, $minutes] = array_pad(explode('.', $raw, 2), 2, '00');
                $allowedBreakMinutes = ((int) $hours * 60) + min(59, max(0, (int) $minutes));
            }

            $deductBreakMinutes = max($allowedBreakMinutes, $regularBreakMinutes);
            $coveredInsideShift = max(0, (int) $regularStart->diffInMinutes($regularEnd));
            $workedInsideShift = max(0, $coveredInsideShift - $deductBreakMinutes);

            $scheduledNetMinutes = max(0, $scheduledMinutes - $allowedBreakMinutes);
            $workedInsideShift = min($workedInsideShift, $scheduledNetMinutes);
        }

        $attendance->total_minutes = $workedInsideShift;
        $attendance->is_late = $late > 0;
        $attendance->late_minutes = $late;
        $attendance->overtime = $overtime;

        if (Schema::hasColumn('attendances', 'overtime_status')) {
            // Recalculation must not silently erase an HR/Admin decision.
            // New overtime starts as review_required; approved minutes can never exceed actual OT.
            if ($overtime <= 0) {
                $attendance->overtime_status = 'none';
                $attendance->approved_overtime_minutes = 0;
                $attendance->overtime_amount = null;
                $attendance->overtime_approved_by = null;
                $attendance->overtime_approved_at = null;
            } elseif (!in_array($attendance->overtime_status, ['pending', 'approved', 'rejected'], true)) {
                $attendance->overtime_status = 'review_required';
                $attendance->approved_overtime_minutes = 0;
                $attendance->overtime_amount = null;
            } elseif ($attendance->overtime_status === 'approved') {
                $attendance->approved_overtime_minutes = min(
                    max(0, (int) ($attendance->approved_overtime_minutes ?? 0)),
                    $overtime
                );
            } else {
                $attendance->approved_overtime_minutes = 0;
                $attendance->overtime_amount = null;
            }
        }

        $attendance->save();
        return $attendance->fresh(['client', 'schedule', 'breaks']);
    }

    private function overlapMinutes(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): int
    {
        $start = $aStart->gt($bStart) ? $aStart->copy() : $bStart->copy();
        $end = $aEnd->lt($bEnd) ? $aEnd->copy() : $bEnd->copy();
        return $end->gt($start) ? (int) $start->diffInMinutes($end) : 0;
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
