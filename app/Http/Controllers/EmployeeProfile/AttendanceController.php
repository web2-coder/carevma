<?php

namespace App\Http\Controllers\EmployeeProfile;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Models\Attendance;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\AttendanceChangeRequestMail;
use App\Models\AttendanceChangeRequest;
use App\Models\EmployeeClientSchedule;
use App\Models\Client;
use App\Events\Notifications;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function clockIn(Request $request)
    {
        try {
            $employee = Employee::where('user_id', auth()->id())->firstOrFail();
            $employeeTz = $employee->timezone ?? 'Asia/Karachi';
            
            $now = Carbon::now($employeeTz);
            $currentDate = $now->toDateString();
            $currentWeekday = strtolower($now->format('l'));
            
            // Check if already clocked in
            $attendance = Attendance::where('employee_id', $employee->id)
                ->where('date', $currentDate)
                ->first();
                
            if ($attendance && $attendance->clock_in) {
                return back()->with('error', 'Already clocked in.');
            }
            
            // Get schedule
            $schedule = EmployeeClientSchedule::where('employee_id', $employee->id)
                ->where('weekday', $currentWeekday)
                ->where('enabled', true)
                ->first();
                
            if (!$schedule) {
                return back()->with('error', 'No shift scheduled for today.');
            }
            
            // Parse shift times in employee timezone
            $shiftStart = Carbon::parse($currentDate . ' ' . $schedule->start_time, $employeeTz);
            $shiftEnd = Carbon::parse($currentDate . ' ' . $schedule->end_time, $employeeTz);
            
            if ($shiftEnd->lt($shiftStart)) {
                $shiftEnd->addDay();
            }
            
            // Calculate late minutes
            $lateMinutes = 0;
            if ($now->gt($shiftStart)) {
                $lateMinutes = $shiftStart->diffInMinutes($now);
            }
            
            // Convert break allowed hours
            $hoursDecimal = $employee->break_allowed_hours ?? 0;
            $hours = floor($hoursDecimal);
            $minutes = round(($hoursDecimal - $hours) * 60);
            if ($minutes === 60) {
                $hours += 1;
                $minutes = 0;
            }
            $breakTime = sprintf('%02d:%02d:%02d', $hours, $minutes, 0);
            
            // Store clock_in in UTC but date in employee's timezone date
            Attendance::updateOrCreate(
                ['employee_id' => $employee->id, 'date' => $currentDate],
                [
                    'client_id' => $schedule->client_id,
                    'clock_in' => $now->copy()->utc(), // Store in UTC
                    'break_limit' => $breakTime,
                    'late_minutes' => $lateMinutes,
                ]
            );
            
            return back()->with('success', 'Clocked in successfully!');
            
        } catch (\Exception $e) {
            \Log::error('Clock-in failed: ' . $e->getMessage());
            return back()->with('error', 'Something went wrong. Please try again.');
        }
    }
    
    public function clockOut(Request $request)
    {
        try {
            $employee = Employee::where('user_id', auth()->id())->firstOrFail();
            $employeeTz = $employee->timezone ?? 'Asia/Karachi';
            
            $now = Carbon::now($employeeTz);
            $currentDate = $now->toDateString();
            $currentWeekday = strtolower($now->format('l'));
            
            // Get attendance
            $attendance = Attendance::where('employee_id', $employee->id)
                ->where('date', $currentDate)
                ->firstOrFail();
                
            // Store clock_out in UTC
            $attendance->clock_out = $now->copy()->utc();
            
            // Handle active break
            $activeBreak = $attendance->breaks()->whereNull('end_time')->first();
            if ($activeBreak) {
                $activeBreak->update(['end_time' => $now->copy()->utc()]);
                $breakSecs = Carbon::parse($activeBreak->start_time)->diffInSeconds($now->copy()->utc());
                $attendance->break_taken = ($attendance->break_taken ?? 0) + $breakSecs;
            }
            
            // Get schedule
            $schedule = EmployeeClientSchedule::where('employee_id', $employee->id)
                ->where('weekday', $currentWeekday)
                ->where('enabled', true)
                ->first();
                
            if ($schedule) {
                $shiftStart = Carbon::parse($currentDate . ' ' . $schedule->start_time, $employeeTz);
                $shiftEnd = Carbon::parse($currentDate . ' ' . $schedule->end_time, $employeeTz);
                
                if ($shiftEnd->lt($shiftStart)) {
                    $shiftEnd->addDay();
                }
                
                // Calculate worked minutes using employee timezone
                $clockInLocal = Carbon::parse($attendance->clock_in)->setTimezone($employeeTz);
                $clockOutLocal = $now; // Already in employee timezone
                $totalMins = $clockInLocal->diffInMinutes($clockOutLocal);
                
                $breakMins = intval(($attendance->break_taken ?? 0) / 60);
                $netMins = max(0, $totalMins - $breakMins);
                
                // Calculate overtime
                $overtime = 0;
                if ($clockOutLocal->gt($shiftEnd)) {
                    $overtime = $shiftEnd->diffInMinutes($clockOutLocal);
                }
                
                $attendance->total_minutes = $netMins;
                $attendance->overtime = $overtime;
            }
            
            $attendance->save();
            
            return back()->with('success', 'Clocked out successfully!');
            
        } catch (\Exception $e) {
            \Log::error('Clock-out failed: ' . $e->getMessage());
            return back()->with('error', 'Something went wrong. Please try again.');
        }
    }
    
    public function startBreak(Request $request)
    {
        try {
            $employee = Employee::where('user_id', auth()->id())->firstOrFail();
            $employeeTz = $employee->timezone ?? 'Asia/Karachi';
            
            $now = Carbon::now($employeeTz);
            $currentDate = $now->toDateString();
            
            $attendance = Attendance::where('employee_id', $employee->id)
                ->where('date', $currentDate)
                ->first();
                
            if (!$attendance) {
                return back()->with('error', 'You must clock in first.');
            }
            
            $attendance->breaks()->create([
                'notes' => $request->notes,
                'start_time' => $now->copy()->utc(), // Store in UTC
            ]);
            
            return back()->with('success', 'Break started.');
            
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to start break.');
        }
    }
    
    public function endBreak(Request $request)
    {
        try {
            $employee = Employee::where('user_id', auth()->id())->firstOrFail();
            $employeeTz = $employee->timezone ?? 'Asia/Karachi';
            
            $now = Carbon::now($employeeTz);
            $currentDate = $now->toDateString();
            
            $attendance = Attendance::where('employee_id', $employee->id)
                ->where('date', $currentDate)
                ->firstOrFail();
                
            $break = $attendance->breaks()
                ->whereNull('end_time')
                ->latest()
                ->first();
                
            if ($break) {
                $endTimeUTC = $now->copy()->utc();
                $break->update(['end_time' => $endTimeUTC]);
                
                $start = Carbon::parse($break->start_time);
                $duration = max(0, $start->diffInSeconds($endTimeUTC));
                
                $attendance->break_taken = ($attendance->break_taken ?? 0) + $duration;
                $attendance->save();
            }
            
            return back()->with('success', 'Break ended.');
            
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to end break.');
        }
    }
    
    public function requestChange(Request $request)
    {
        try {
            $attendance = Attendance::findOrFail($request->attendance_id);
            $employee = Employee::where('user_id', auth()->id())->firstOrFail();
            
            if ($attendance->employee_id !== $employee->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $request->validate([
                'attendance_id' => 'required|exists:attendances,id',
                'clock_in' => 'required|date_format:H:i',
                'clock_out' => 'required|date_format:H:i|after:clock_in',
                'reason' => 'required|string|max:255',
                'date' => 'required|unique:attendance_change_requests,date,NULL,id,employee_id,' . $attendance->employee_id,
            ]);
            
            $changeRequest = AttendanceChangeRequest::create([
                'attendance_id' => $attendance->id,
                'employee_id' => $attendance->employee_id,
                'date' => $attendance->date,
                'requested_clock_in' => $request->clock_in,
                'requested_clock_out' => $request->clock_out,
                'reason' => $request->reason,
                'status' => 'pending',
            ]);
            
            $admin = User::where('role', 'admin')->first();
            if ($admin) {
                Mail::to($admin->email)->send(new AttendanceChangeRequestMail($changeRequest));
            }
            
            return response()->json(['message' => 'Request submitted successfully.']);
            
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to submit request.'], 500);
        }
    }
}