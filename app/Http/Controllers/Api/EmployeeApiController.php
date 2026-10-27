<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceChangeRequest;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeClientSchedule;
use App\Support\MediaUrl;
use App\Services\TrustedTimeService;
use App\Services\ClientTimezoneService;
use DateTimeZone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EmployeeApiController extends Controller
{
    public function __construct(
        private TrustedTimeService $clock,
        private ClientTimezoneService $timezones,
    ) {
    }

    public function dashboard(Request $request)
    {
        $employee = $this->employee($request);
        $employeeTimezone = $this->validTimezone($employee->timezone ?: 'Asia/Karachi', 'Asia/Karachi');
        $nowUtc = $this->clock->now('UTC');
        $employeeNow = $nowUtc->copy()->setTimezone($employeeTimezone);

        $active = Attendance::with(['breaks', 'client:id,name,business_name,timezone', 'schedule:id,client_id,employee_id,break_allowed_hours,start_time,end_time,weekday,timezone'])
            ->where('employee_id', $employee->id)
            ->whereNull('clock_out')
            ->latest('id')
            ->first();

        if ($active && $active->clock_in) {
            $clockInUtc = $active->clock_in->copy()->utc();
            $elapsedSeconds = max(0, $nowUtc->timestamp - $clockInUtc->timestamp);

            $openBreak = $active->breaks
                ->filter(fn ($break) => empty($break->end_time))
                ->sortByDesc('id')
                ->first();

            $currentBreakSeconds = 0;
            if ($openBreak && $openBreak->start_time) {
                $breakStartUtc = $openBreak->start_time instanceof \Carbon\Carbon
                    ? $openBreak->start_time->copy()->utc()
                    : Carbon::parse($openBreak->start_time, 'UTC')->utc();
                $currentBreakSeconds = max(0, $nowUtc->timestamp - $breakStartUtc->timestamp);
            }

            // Stable duration anchors for the React timers. These values are based on
            // absolute UTC instants and therefore survive refreshes and break actions.
            $active->setAttribute('session_elapsed_seconds', $elapsedSeconds);
            $active->setAttribute('elapsed_seconds', $elapsedSeconds); // backward compatibility
            $active->setAttribute('completed_break_seconds', max(0, (int) ($active->break_taken ?? 0)));
            $active->setAttribute('current_break_elapsed_seconds', $currentBreakSeconds);
            $active->setAttribute('clock_in_utc', $clockInUtc->toIso8601ZuluString());
            $active->setAttribute('va_timezone', $active->timezone_snapshot ?: $employeeTimezone);
            $active->setAttribute('doctor_timezone', $active->client?->timezone);
            $scheduleBreakCode = $active->schedule?->break_allowed_hours ?? 0;
            // break_limit is snapshotted on attendance at clock-in; keep that stable for history.
            $active->setAttribute('break_limit', $active->break_limit ?: $this->breakCodeToHms($scheduleBreakCode));
            $active->setAttribute('break_allowance_source', 'schedule');
        }

        $allSchedules = EmployeeClientSchedule::with('client:id,name,business_name,timezone')
            ->where('employee_id', $employee->id)
            ->orderBy('start_time')
            ->get();

        $todaySchedules = $active
            ? collect()
            : $allSchedules
            ->filter(fn (EmployeeClientSchedule $schedule) => $this->scheduleIsEnabled($schedule->enabled))
            ->map(function (EmployeeClientSchedule $schedule) use ($employeeTimezone, $employee) {
                // The Client / Doctor timezone is the scheduling source of truth.
                // The VA timezone is display-only and represents the same instant converted locally.
                $context = $this->scheduleContext($schedule, $employeeTimezone);
                if (!$context || $context['status'] !== 'open') return null;

                $occurrenceDate = $context['start']->toDateString();
                $alreadyCompleted = Attendance::where('employee_id', $employee->id)
                    ->where('schedule_id', $schedule->id)
                    ->whereDate('date', $occurrenceDate)
                    ->whereNotNull('clock_out')
                    ->exists();

                if ($alreadyCompleted) return null;

                return $this->decorateScheduleTimes($schedule, $context, $employeeTimezone);
            })
            ->filter()
            ->values();

        $nextSchedules = $active
            ? collect()
            : $allSchedules
                ->filter(fn (EmployeeClientSchedule $schedule) => $this->scheduleIsEnabled($schedule->enabled))
                ->map(function (EmployeeClientSchedule $schedule) use ($employeeTimezone) {
                    $context = $this->nextScheduleContext($schedule, $employeeTimezone);
                    if (!$context || $context['status'] === 'ended') return null;
                    return $this->decorateScheduleTimes($schedule, $context, $employeeTimezone);
                })
                ->filter()
                ->sortBy(fn (EmployeeClientSchedule $schedule) => (string) $schedule->client_shift_starts_at)
                ->values();

        return response()->json([
            'employee' => $employee->load(['department', 'designation', 'employeeStatus']),
            'active_attendance' => $active,
            'today_schedules' => $todaySchedules,
            'next_schedules' => $nextSchedules,
            'all_schedules' => $allSchedules->values(),
            'timezone' => $employeeTimezone,
            'now' => $employeeNow->toIso8601String(),
            'now_utc' => $nowUtc->toIso8601ZuluString(),
            'time_source' => $this->clock->source(),
            'server_weekday' => $employeeNow->format('l'),
            'completed_today' => $this->hasCompletedAttendanceOnCurrentClientDay($employee->id),
        ]);
    }

    private function hasCompletedAttendanceOnCurrentClientDay(int $employeeId): bool
    {
        $rows = Attendance::with('client:id,timezone')
            ->where('employee_id', $employeeId)
            ->whereNotNull('clock_out')
            ->where('clock_in', '>=', $this->clock->now('UTC')->copy()->subDays(2))
            ->latest('clock_in')
            ->limit(50)
            ->get();

        foreach ($rows as $row) {
            $zone = $this->timezones->forAttendance($row, true);
            $recordDate = $this->timezones->localDate($row->clock_in, $zone);
            if ($recordDate && $recordDate === $this->timezones->now($zone)->toDateString()) {
                return true;
            }
        }

        return false;
    }

    public function schedules(Request $request)
    {
        $employee = $this->employee($request);
        $employeeTimezone = $this->validTimezone($employee->timezone ?: 'Asia/Karachi', 'Asia/Karachi');
        $order = "FIELD(weekday,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')";

        $schedules = EmployeeClientSchedule::with('client:id,name,business_name,timezone')
            ->where('employee_id', $employee->id)
            ->orderByRaw($order)
            ->orderBy('start_time')
            ->get();

        $schedules->each(function (EmployeeClientSchedule $schedule) use ($employeeTimezone) {
            $context = $this->nextScheduleContext($schedule, $employeeTimezone);
            if ($context) {
                $this->decorateScheduleTimes($schedule, $context, $employeeTimezone);
            } else {
                $schedule->setAttribute('employee_timezone', $employeeTimezone);
                $schedule->setAttribute('client_timezone', $schedule->client?->timezone);
            }
        });

        return response()->json($schedules);
    }

    public function attendance(Request $request)
    {
        $employee = $this->employee($request);
        $query = Attendance::with(['breaks', 'client:id,name,business_name,timezone', 'schedule:id,client_id,employee_id,timezone,start_time,end_time,weekday'])->where('employee_id', $employee->id);
        $this->applyEmployeeAttendanceLocalDateFilter(
            $query,
            $employee->id,
            $request->input('from'),
            $request->input('to')
        );
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('date', 'like', "%{$search}%")->orWhere('clock_in', 'like', "%{$search}%")->orWhere('clock_out', 'like', "%{$search}%"));
        }
        $attendance = $query->latest('date')->latest('id')->paginate(min((int) $request->query('per_page', 25), 100));
        $attendance->through(function (Attendance $row) use ($employee) {
            $clientZone = $this->timezones->forAttendance($row, true);
            $employeeZone = $this->timezones->valid($employee->timezone, 'UTC');
            $row->setAttribute('client_timezone', $clientZone);
            $row->setAttribute('employee_timezone', $employeeZone);
            $row->setAttribute('clock_in_client', $this->timezones->isoInZone($row->clock_in, $clientZone));
            $row->setAttribute('clock_out_client', $this->timezones->isoInZone($row->clock_out, $clientZone));
            $row->setAttribute('clock_in_employee', $this->timezones->isoInZone($row->clock_in, $employeeZone));
            $row->setAttribute('clock_out_employee', $this->timezones->isoInZone($row->clock_out, $employeeZone));
            return $row;
        });
        return response()->json($attendance);
    }

    private function applyEmployeeAttendanceLocalDateFilter($query, int $employeeId, ?string $from, ?string $to): void
    {
        if (!$from && !$to) {
            return;
        }

        if (!Schema::hasColumn('attendances', 'client_id') || !Schema::hasColumn('attendances', 'clock_in')) {
            if ($from) $query->whereDate('date', '>=', $from);
            if ($to) $query->whereDate('date', '<=', $to);
            return;
        }

        $clientIds = Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereNotNull('client_id')
            ->distinct()
            ->pluck('client_id');
        $clients = Client::query()->whereIn('id', $clientIds)->get(['id', 'timezone']);

        $query->where(function ($outer) use ($clients, $from, $to) {
            foreach ($clients as $client) {
                [$startUtc, $endUtc] = $this->timezones->utcRangeForLocalFilters($from, $to, $client);
                $outer->orWhere(function ($clientQuery) use ($client, $startUtc, $endUtc) {
                    $clientQuery->where('client_id', $client->id);
                    if ($startUtc) $clientQuery->where('clock_in', '>=', $startUtc);
                    if ($endUtc) $clientQuery->where('clock_in', '<=', $endUtc);
                });
            }

            $outer->orWhere(function ($legacy) use ($from, $to) {
                $legacy->whereNull('client_id');
                if ($from) $legacy->whereDate('date', '>=', $from);
                if ($to) $legacy->whereDate('date', '<=', $to);
            });
        });
    }

    public function changeRequests(Request $request)
    {
        $employee = $this->employee($request);
        return response()->json(AttendanceChangeRequest::where('employee_id', $employee->id)->latest('id')->get());
    }

    public function requestChange(Request $request)
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'attendance_id' => ['required', 'exists:attendances,id'],
            'clock_in' => ['required', 'date_format:H:i'],
            'clock_out' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $attendance = Attendance::where('id', $data['attendance_id'])->where('employee_id', $employee->id)->firstOrFail();
        if (AttendanceChangeRequest::where('attendance_id', $attendance->id)->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'A pending request already exists for this attendance record.'], 422);
        }
        $change = AttendanceChangeRequest::create([
            'attendance_id' => $attendance->id, 'employee_id' => $employee->id, 'date' => $attendance->date,
            'requested_clock_in' => $data['clock_in'], 'requested_clock_out' => $data['clock_out'],
            'old_clock_in' => $attendance->clock_in ? $this->timezones->toZone($attendance->clock_in, $this->timezones->forAttendance($attendance, false))?->format('H:i:s') : null,
            'old_clock_out' => $attendance->clock_out ? $this->timezones->toZone($attendance->clock_out, $this->timezones->forAttendance($attendance, false))?->format('H:i:s') : null,
            'reason' => $data['reason'], 'status' => 'pending',
        ]);
        return response()->json(['message' => 'Attendance correction request submitted.', 'request' => $change], 201);
    }

    public function clockIn(Request $request)
    {
        $employee = $this->employee($request);
        $data = $request->validate(['schedule_id' => ['required', 'exists:employee_client_schedules,id']]);
        if (Attendance::where('employee_id', $employee->id)->whereNull('clock_out')->exists()) return response()->json(['message' => 'You already have an active work session.'], 422);

        $schedule = EmployeeClientSchedule::with('client:id,name,business_name,timezone')->where('id', $data['schedule_id'])->where('employee_id', $employee->id)->firstOrFail();
        if (!$this->scheduleIsEnabled($schedule->enabled)) {
            return response()->json(['message' => 'This schedule is disabled.'], 422);
        }
        $tz = $this->calculationTimezone($schedule, $employee->timezone ?: 'Asia/Karachi');
        $now = $this->clock->now($tz);
        $window = $this->scheduleWindow($schedule, $now);
        if (!$window) return response()->json(['message' => 'This schedule is not available for the current day.'], 422);
        [$start, $end] = $window;
        $date = $start->toDateString();
        if ($now->lt($start->copy()->subMinutes(15))) {
            $employeeZone = $this->timezones->valid($employee->timezone, 'UTC');
            $opens = $start->copy()->subMinutes(15);
            return response()->json([
                'message' => 'Check-in is not open yet. It opens at '.$opens->format('D, M j, g:i A').' ('.$tz.') / '.$opens->copy()->setTimezone($employeeZone)->format('D, M j, g:i A').' ('.$employeeZone.').',
                'code' => 'CHECK_IN_NOT_OPEN',
                'check_in_opens_at_client' => $opens->toIso8601String(),
                'check_in_opens_at_employee' => $opens->copy()->setTimezone($employeeZone)->toIso8601String(),
            ], 422);
        }
        if ($now->gte($end)) {
            $employeeZone = $this->timezones->valid($employee->timezone, 'UTC');
            return response()->json([
                'message' => 'This shift ended at '.$end->format('D, M j, g:i A').' ('.$tz.') / '.$end->copy()->setTimezone($employeeZone)->format('D, M j, g:i A').' ('.$employeeZone.'). Use the next scheduled shift to check in.',
                'code' => 'SHIFT_ENDED',
            ], 422);
        }

        $alreadyCompleted = Attendance::where('employee_id', $employee->id)
            ->where('schedule_id', $schedule->id)
            ->whereDate('date', $date)
            ->whereNotNull('clock_out')
            ->exists();

        if ($alreadyCompleted) {
            return response()->json(['message' => 'You already completed this scheduled shift. Check-in will be available again for your next shift, starting 15 minutes before it begins.'], 422);
        }
        $nowMinute = $now->copy()->startOfMinute();
        $late = $nowMinute->gt($start) ? (int) $start->copy()->startOfMinute()->diffInMinutes($nowMinute) : 0;
        // Break allowance belongs to this exact VA + Client schedule.
        // Snapshot it onto attendance at clock-in so historical billing remains stable even if the schedule changes later.
        $breakCode = $schedule->break_allowed_hours ?? 0;
        $breakLimit = $this->breakCodeToHms($breakCode);

        $payload = [
            'employee_id' => $employee->id, 'date' => $date, 'clock_in' => $now->copy()->utc(),
            'break_limit' => $breakLimit, 'break_taken' => 0, 'is_late' => $late > 0, 'late_minutes' => $late, 'overtime' => 0,
        ];
        if (Schema::hasColumn('attendances', 'client_id')) $payload['client_id'] = $schedule->client_id;
        if (Schema::hasColumn('attendances', 'schedule_id')) $payload['schedule_id'] = $schedule->id;
        if (Schema::hasColumn('attendances', 'timezone_snapshot')) $payload['timezone_snapshot'] = $tz;
        if (Schema::hasColumn('attendances', 'shift_start_snapshot')) $payload['shift_start_snapshot'] = $start->copy()->utc();
        if (Schema::hasColumn('attendances', 'shift_end_snapshot')) $payload['shift_end_snapshot'] = $end->copy()->utc();
        if (Schema::hasColumn('attendances', 'overtime_status')) $payload['overtime_status'] = 'none';
        $attendance = Attendance::create($payload);

        return response()->json(['message' => 'Clocked in successfully.', 'attendance' => $attendance->load(['client', 'breaks'])], 201);
    }

    public function startBreak(Request $request)
    {
        $employee = $this->employee($request);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $attendance = Attendance::where('employee_id', $employee->id)->whereNull('clock_out')->latest('id')->first();
        if (!$attendance) return response()->json(['message' => 'Clock in before starting a break.'], 422);
        if ($attendance->breaks()->whereNull('end_time')->exists()) return response()->json(['message' => 'A break is already active.'], 422);
        $allowedSeconds = $this->hmsToSeconds($attendance->break_limit);
        if ($allowedSeconds <= 0) return response()->json(['message' => 'No break time is allowed for this schedule.'], 422);
        if ((int) ($attendance->break_taken ?? 0) >= $allowedSeconds) return response()->json(['message' => 'Your allowed break time for this schedule has already been used.'], 422);
        $break = $attendance->breaks()->create(['start_time' => $this->clock->now('UTC'), 'notes' => $data['notes'] ?? null]);
        return response()->json(['message' => 'Break started.', 'break' => $break], 201);
    }

    public function endBreak(Request $request)
    {
        $employee = $this->employee($request);
        $attendance = Attendance::where('employee_id', $employee->id)->whereNull('clock_out')->latest('id')->first();
        if (!$attendance) return response()->json(['message' => 'No active attendance session.'], 422);
        $break = $attendance->breaks()->whereNull('end_time')->latest('id')->first();
        if (!$break) return response()->json(['message' => 'No active break.'], 422);
        $end = $this->clock->now('UTC');
        $breakStartUtc = $break->start_time instanceof Carbon ? $break->start_time->copy()->utc() : Carbon::parse($break->start_time, 'UTC')->utc();
        $seconds = max(0, $end->timestamp - $breakStartUtc->timestamp);
        $break->update(['end_time' => $end]);
        $attendance->increment('break_taken', $seconds);
        return response()->json(['message' => 'Break ended.']);
    }

    public function clockOut(Request $request)
    {
        $employee = $this->employee($request);
        $attendance = Attendance::where('employee_id', $employee->id)->whereNull('clock_out')->latest('id')->first();
        if (!$attendance) return response()->json(['message' => 'No active attendance session.'], 422);
        $attendance->loadMissing(['client', 'schedule']);
        $tz = $this->timezones->forAttendance($attendance, false);
        $now = $this->clock->now($tz);
        $openBreak = $attendance->breaks()->whereNull('end_time')->latest('id')->first();
        if ($openBreak) {
            $endUtc = $now->copy()->utc();
            $breakStartUtc = $openBreak->start_time instanceof Carbon ? $openBreak->start_time->copy()->utc() : Carbon::parse($openBreak->start_time, 'UTC')->utc();
            $seconds = max(0, $endUtc->timestamp - $breakStartUtc->timestamp);
            $openBreak->update(['end_time' => $endUtc]); $attendance->break_taken = ($attendance->break_taken ?? 0) + $seconds;
        }
        $attendance->clock_out = $now->copy()->utc();
        $schedule = $attendance->schedule_id ? EmployeeClientSchedule::find($attendance->schedule_id) : null;
        $clockIn = $attendance->clock_in->copy()->setTimezone($tz); $regularStart = $clockIn->copy()->startOfMinute(); $regularEnd = $now->copy()->startOfMinute(); $overtime = 0; $late = 0;
        if ($attendance->shift_start_snapshot && $attendance->shift_end_snapshot) {
            $shiftStart = $attendance->shift_start_snapshot->copy()->setTimezone($tz)->startOfMinute();
            $shiftEnd = $attendance->shift_end_snapshot->copy()->setTimezone($tz)->startOfMinute();
            if ($regularStart->lt($shiftStart)) $regularStart = $shiftStart->copy();
            if ($regularStart->gt($shiftStart)) $late = (int) $shiftStart->diffInMinutes($regularStart);
            if ($regularEnd->gt($shiftEnd)) { $overtime = (int) $shiftEnd->diffInMinutes($regularEnd); $regularEnd = $shiftEnd->copy(); }
        } elseif ($schedule) {
            $shiftStart = Carbon::parse($attendance->date->format('Y-m-d').' '.$schedule->start_time, $tz)->startOfMinute();
            $shiftEnd = Carbon::parse($attendance->date->format('Y-m-d').' '.$schedule->end_time, $tz)->startOfMinute(); if ($shiftEnd->lte($shiftStart)) $shiftEnd->addDay();
            if ($regularStart->lt($shiftStart)) $regularStart = $shiftStart->copy();
            if ($regularStart->gt($shiftStart)) $late = (int) $shiftStart->diffInMinutes($regularStart);
            if ($regularEnd->gt($shiftEnd)) { $overtime = (int) $shiftEnd->diffInMinutes($regularEnd); $regularEnd = $shiftEnd->copy(); }
        }
        $breakMinutes = (int) floor(($attendance->break_taken ?? 0) / 60);
        $attendance->total_minutes = max(0, (int) $regularStart->diffInMinutes($regularEnd) - $breakMinutes);
        $attendance->is_late = $late > 0; $attendance->late_minutes = $late; $attendance->overtime = $overtime;
        if (Schema::hasColumn('attendances', 'overtime_status')) $attendance->overtime_status = $overtime > 0 ? 'pending' : 'none';
        $attendance->save();
        return response()->json(['message' => 'Clocked out successfully.', 'attendance' => $attendance->fresh(['client', 'breaks'])]);
    }

    public function profile(Request $request)
    {
        $employee = $this->employee($request)->load(['user', 'department', 'designation', 'employmentType', 'shiftType', 'employeeStatus', 'expertise', 'reportingManager']);
        $employee->setAttribute('upload_documents', $this->normalizeDocuments($employee->upload_documents));
        $missing = $employee->missingRequiredProfileFields();
        $total = count(Employee::REQUIRED_SELF_SERVICE_FIELDS);
        $employee->setAttribute('profile_missing_fields', $missing);
        $employee->setAttribute('profile_completion_percent', $total ? (int) round((($total - count($missing)) / $total) * 100) : 100);
        return response()->json($employee);
    }

    public function updateProfile(Request $request)
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($request->user()->id)],
            'phone' => ['required', 'string', 'max:60'], 'date_of_birth' => ['required', 'date', 'before_or_equal:today'], 'gender' => ['required', Rule::in(['male','female','other'])],
            'marital_status' => ['required', 'string', 'max:60'], 'about_me_notes' => ['nullable', 'string', 'max:3000'],
            'profile_picture' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:800'],
            'documents.*' => ['nullable', 'file', 'max:5120', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
        ]);
        if ($request->hasFile('profile_picture')) {
            if ($employee->profile_picture) Storage::disk('public')->delete(ltrim(str_replace('/storage/', '', $employee->profile_picture), '/'));
            $path = $request->file('profile_picture')->store('employees/profile_pictures', 'public');
            $data['profile_picture'] = '/storage/'.$path;
        }
        unset($data['documents']);
        $documents = $this->normalizeDocuments($employee->upload_documents);
        foreach ($request->file('documents', []) as $file) $documents[] = '/storage/'.$file->store('employees/documents', 'public');
        if ($request->hasFile('documents')) $data['upload_documents'] = $documents;
        $employee->update($data);
        $employee->refresh();
        $employee->profile_completed = $employee->hasCompleteSelfServiceProfile();
        $employee->save();

        $request->user()->update(['name' => trim($employee->first_name.' '.$employee->last_name), 'email' => $employee->email]);

        return response()->json([
            'message' => $employee->profile_completed ? 'Profile completed. Your CareVMA workspace is now unlocked.' : 'Profile saved.',
            'employee' => $employee->fresh(),
            'profile_completed' => (bool) $employee->profile_completed,
            'missing_fields' => $employee->missingRequiredProfileFields(),
        ]);
    }

    public function removeDocument(Request $request)
    {
        $employee = $this->employee($request); $data = $request->validate(['path' => ['required', 'string']]);
        $documents = $this->normalizeDocuments($employee->upload_documents);
        if (!in_array($data['path'], $documents, true)) return response()->json(['message' => 'Document not found.'], 404);
        Storage::disk('public')->delete(ltrim(str_replace('/storage/', '', $data['path']), '/'));
        $employee->update(['upload_documents' => array_values(array_filter($documents, fn ($p) => $p !== $data['path']))]);
        return response()->json(['message' => 'Document removed.']);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate(['current_password' => ['required'], 'password' => ['required', 'string', 'min:8', 'confirmed']]);
        if (!Hash::check($data['current_password'], $request->user()->password)) return response()->json(['message' => 'Current password is incorrect.', 'errors' => ['current_password' => ['Current password is incorrect.']]], 422);
        $hash = Hash::make($data['password']); $request->user()->update(['password' => $hash]);
        $this->employee($request)->update(['password' => $hash]);
        return response()->json(['message' => 'Password changed successfully.']);
    }


    private function scheduleWindow(EmployeeClientSchedule $schedule, Carbon $now): ?array
    {
        $context = $this->scheduleContext($schedule, $now->getTimezone()->getName());
        return $context ? [$context['start'], $context['end']] : null;
    }

    private function scheduleContext(EmployeeClientSchedule $schedule, string $fallbackTimezone): ?array
    {
        $weekday = $this->normalizeWeekday($schedule->weekday);
        if (!$weekday) return null;

        $timezone = $this->calculationTimezone($schedule, $fallbackTimezone);
        $localNow = $this->clock->now($timezone);
        $baseDate = null;

        // One-time schedules are tied to the Client / Doctor local date. Legacy rows
        // without schedule_date keep their historical weekly behavior.
        if (!$schedule->repeat && $schedule->schedule_date) {
            $baseDate = Carbon::parse($schedule->schedule_date, $timezone)->startOfDay();
        } else {
            $todayName = $localNow->format('l');
            $yesterday = $localNow->copy()->subDay();

            if (strcasecmp($weekday, $todayName) === 0) {
                $baseDate = $localNow->copy()->startOfDay();
            } elseif (strcasecmp($weekday, $yesterday->format('l')) === 0) {
                // Keep an overnight VA shift active after local midnight.
                $candidateStart = Carbon::parse($yesterday->toDateString().' '.$schedule->start_time, $timezone);
                $candidateEnd = Carbon::parse($yesterday->toDateString().' '.$schedule->end_time, $timezone);
                if ($candidateEnd->lte($candidateStart)) {
                    $candidateEnd->addDay();
                    if ($localNow->lt($candidateEnd)) {
                        $baseDate = $yesterday->copy()->startOfDay();
                    }
                }
            }
        }

        if (!$baseDate) return null;

        $start = Carbon::parse($baseDate->toDateString().' '.$schedule->start_time, $timezone);
        $end = Carbon::parse($baseDate->toDateString().' '.$schedule->end_time, $timezone);
        if ($end->lte($start)) $end->addDay();

        $opensAt = $start->copy()->subMinutes(15);
        $status = $localNow->lt($opensAt)
            ? 'early'
            : ($localNow->gte($end) ? 'ended' : 'open');

        return [
            'now' => $localNow,
            'start' => $start,
            'end' => $end,
            'opens_at' => $opensAt,
            'status' => $status,
            'timezone' => $timezone,
        ];
    }

    private function nextScheduleContext(EmployeeClientSchedule $schedule, string $fallbackTimezone): ?array
    {
        $weekday = $this->normalizeWeekday($schedule->weekday);
        if (!$weekday) return null;

        $timezone = $this->calculationTimezone($schedule, $fallbackTimezone);
        $now = $this->clock->now($timezone);

        $current = $this->scheduleContext($schedule, $timezone);

        // An exact-date schedule never rolls forward to a future week. Return the
        // exact occurrence even after it ended so My Schedule can still display it.
        if (!$schedule->repeat && $schedule->schedule_date) {
            return $current;
        }

        if ($current && $current['status'] !== 'ended') {
            return $current;
        }

        $dayNumbers = [
            'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6,
        ];
        $target = $dayNumbers[$weekday] ?? null;
        if ($target === null) return null;

        $daysAhead = ($target - $now->dayOfWeek + 7) % 7;
        if ($daysAhead === 0) $daysAhead = 7;

        $baseDate = $now->copy()->addDays($daysAhead)->startOfDay();
        $start = Carbon::parse($baseDate->toDateString().' '.$schedule->start_time, $timezone);
        $end = Carbon::parse($baseDate->toDateString().' '.$schedule->end_time, $timezone);
        if ($end->lte($start)) $end->addDay();
        $opensAt = $start->copy()->subMinutes(15);

        return [
            'now' => $now,
            'start' => $start,
            'end' => $end,
            'opens_at' => $opensAt,
            'status' => $now->lt($opensAt) ? 'early' : ($now->gte($end) ? 'ended' : 'open'),
            'timezone' => $timezone,
        ];
    }

    private function decorateScheduleTimes(EmployeeClientSchedule $schedule, array $context, string $employeeTimezone): EmployeeClientSchedule
    {
        $employeeTimezone = $this->validTimezone($employeeTimezone, $context['timezone']);
        $clientTimezone = $this->validTimezone($schedule->client?->timezone ?: 'UTC', 'UTC');

        $schedule->setAttribute('check_in_status', $context['status']);
        $schedule->setAttribute('employee_timezone', $employeeTimezone);
        $schedule->setAttribute('client_timezone', $clientTimezone);
        $schedule->setAttribute('check_in_opens_at', $context['opens_at']->copy()->setTimezone($employeeTimezone)->toIso8601String());
        $schedule->setAttribute('shift_starts_at', $context['start']->copy()->setTimezone($employeeTimezone)->toIso8601String());
        $schedule->setAttribute('shift_ends_at', $context['end']->copy()->setTimezone($employeeTimezone)->toIso8601String());
        $schedule->setAttribute('client_shift_starts_at', $context['start']->copy()->setTimezone($clientTimezone)->toIso8601String());
        $schedule->setAttribute('client_shift_ends_at', $context['end']->copy()->setTimezone($clientTimezone)->toIso8601String());
        $schedule->setAttribute('employee_now', $this->clock->now($employeeTimezone)->toIso8601String());
        $schedule->setAttribute('client_now', $this->clock->now($clientTimezone)->toIso8601String());
        $schedule->setAttribute('employee_weekday', $context['start']->copy()->setTimezone($employeeTimezone)->format('l'));
        $schedule->setAttribute('client_weekday', $context['start']->copy()->setTimezone($clientTimezone)->format('l'));

        return $schedule;
    }

    private function calculationTimezone(EmployeeClientSchedule $schedule, ?string $fallbackTimezone = null): string
    {
        // CareVMA scheduling/calculation source of truth is always the Client / Doctor timezone.
        // Use the IANA timezone name so DST is resolved automatically for the actual date
        // (for example America/New_York is GMT-4 on Oct 26, 2026, while Asia/Karachi is GMT+5).
        return $this->timezones->forSchedule($schedule, $schedule->client);
    }

    private function breakCodeToHms(mixed $value): string
    {
        // CareVMA break values use H.MM notation, not decimal hours.
        // Examples: 0.30 = 30 minutes, 0.50 = 50 minutes, 1.30 = 1 hour 30 minutes.
        $formatted = number_format(max(0, (float) $value), 2, '.', '');
        [$hours, $minutes] = array_map('intval', explode('.', $formatted, 2));
        $minutes = min(59, max(0, $minutes));
        return sprintf('%02d:%02d:00', $hours, $minutes);
    }

    private function hmsToSeconds(mixed $value): int
    {
        if ($value === null || $value === '') return 0;
        $parts = array_map('intval', explode(':', (string) $value));
        $hours = $parts[0] ?? 0;
        $minutes = $parts[1] ?? 0;
        $seconds = $parts[2] ?? 0;
        return max(0, ($hours * 3600) + ($minutes * 60) + $seconds);
    }

    private function validTimezone(?string $timezone, string $fallback = 'UTC'): string
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') return $fallback;

        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function scheduleIsEnabled(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return (int) $value === 1;

        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'enabled', 'yes', 'on'], true);
    }

    private function normalizeWeekday(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') return null;

        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        if (ctype_digit($raw)) {
            $n = (int) $raw;
            // Support both Carbon-style 0..6 and ISO-style 1..7 legacy values.
            if ($n >= 0 && $n <= 6) return $days[$n];
            if ($n === 7) return 'Sunday';
        }

        foreach ($days as $day) {
            if (strcasecmp($raw, $day) === 0) return $day;
        }

        return null;
    }

    private function employee(Request $request): Employee
    {
        return Employee::where('user_id', $request->user()->id)->firstOrFail();
    }

    private function normalizeDocuments(mixed $value): array
    {
        if (!is_array($value)) {
            if (!$value) return [];
            $value = json_decode($value, true);
            if (is_string($value)) $value = json_decode($value, true);
        }

        if (!is_array($value)) return [];

        return array_values(array_filter(array_map(
            fn ($path) => is_string($path) ? MediaUrl::normalize($path) : null,
            $value
        )));
    }
}
