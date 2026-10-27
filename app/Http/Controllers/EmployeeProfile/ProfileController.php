<?php

namespace App\Http\Controllers\EmployeeProfile;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Models\Attendance;
use App\Models\EmployeeClientSchedule;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Http\Request;
use App\Events\Notifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
public function index()
{
    $employee = Employee::where('user_id', auth()->id())->firstOrFail();

    // Get current time in employee's timezone
    $employeeTz = $employee->timezone ?? 'Asia/Karachi';
    $now = Carbon::now($employeeTz);
    
    // Get current date and time
    $currentDate = $now->toDateString();
    $currentTime = $now->format('H:i:s');
    $currentHour = (int) $now->format('H');
    $currentWeekday = strtolower($now->format('l'));
    
    // Determine which shift to show based on current time
    $shiftDate = $currentDate;
    $shiftWeekday = $currentWeekday;
    
    // Check if we need to use previous day's shift (for overnight shifts)
    // If current time is between 12 AM and 6 AM, it might be part of previous day's overnight shift
    if ($currentHour >= 0 && $currentHour < 6) {
        // First check if there's an overnight shift from previous day that ends today
        $prevDate = Carbon::parse($currentDate)->subDay();
        $prevWeekday = strtolower($prevDate->format('l'));
        
        $prevShift = EmployeeClientSchedule::where('employee_id', $employee->id)
            ->where('weekday', $prevWeekday)
            ->where('enabled', true)
            ->first();
            
        if ($prevShift) {
            $prevShiftStart = Carbon::parse($prevDate->toDateString() . ' ' . $prevShift->start_time, $employeeTz);
            $prevShiftEnd = Carbon::parse($prevDate->toDateString() . ' ' . $prevShift->end_time, $employeeTz);
            
            // If this is an overnight shift (end time is next day)
            if ($prevShiftEnd->lt($prevShiftStart)) {
                $prevShiftEnd->addDay();
                
                // If current time is between shift start and end, use previous day's shift
                if ($now->between($prevShiftStart, $prevShiftEnd)) {
                    $shiftDate = $prevDate->toDateString();
                    $shiftWeekday = $prevWeekday;
                }
            }
        }
    }
    
    // Also check if current time is after midnight but before shift end for overnight shifts
    // Get all shifts for today and check if any overnight shift from yesterday is still active
    $todaysShift = EmployeeClientSchedule::where('employee_id', $employee->id)
        ->where('weekday', $shiftWeekday)
        ->where('enabled', true)
        ->first();
    
    // If no shift found for today, check if we're in an overnight shift from yesterday
    if (!$todaysShift && $currentHour >= 0 && $currentHour < 12) {
        $yesterday = Carbon::parse($currentDate)->subDay();
        $yesterdayWeekday = strtolower($yesterday->format('l'));
        
        $yesterdayShift = EmployeeClientSchedule::where('employee_id', $employee->id)
            ->where('weekday', $yesterdayWeekday)
            ->where('enabled', true)
            ->first();
            
        if ($yesterdayShift) {
            $yesterdayStart = Carbon::parse($yesterday->toDateString() . ' ' . $yesterdayShift->start_time, $employeeTz);
            $yesterdayEnd = Carbon::parse($yesterday->toDateString() . ' ' . $yesterdayShift->end_time, $employeeTz);
            
            if ($yesterdayEnd->lt($yesterdayStart)) {
                $yesterdayEnd->addDay();
                
                if ($now->between($yesterdayStart, $yesterdayEnd)) {
                    $shiftDate = $yesterday->toDateString();
                    $shiftWeekday = $yesterdayWeekday;
                }
            }
        }
    }
    
    // Get today's attendance
    $attendanceToday = Attendance::where('employee_id', $employee->id)
        ->where('date', $shiftDate) // Use the determined shift date
        ->first();
    
    // If no attendance found for the shift date, check current date
    if (!$attendanceToday) {
        $attendanceToday = Attendance::where('employee_id', $employee->id)
            ->where('date', $currentDate)
            ->first();
    }
    
    // Convert attendance times to employee timezone
    if ($attendanceToday) {
        if ($attendanceToday->clock_in) {
            $attendanceToday->clock_in_local = Carbon::parse($attendanceToday->clock_in)->timezone($employeeTz);
        }
        if ($attendanceToday->clock_out) {
            $attendanceToday->clock_out_local = Carbon::parse($attendanceToday->clock_out)->timezone($employeeTz);
        }
    }
    
    // Get shift for the determined weekday
    $shift = EmployeeClientSchedule::where('employee_id', $employee->id)
        ->where('weekday', $shiftWeekday)
        ->where('enabled', true)
        ->first();
    
    $shiftInfo = null;
    $canClockIn = false;
    $shiftStartTimestamp = null;
    $shiftEndTimestamp = null;
    $isShiftCompleted = false;
    
    if ($shift) {
        // Parse shift times in employee timezone
        $shiftStart = Carbon::parse($shiftDate . ' ' . $shift->start_time, $employeeTz);
        $shiftEnd = Carbon::parse($shiftDate . ' ' . $shift->end_time, $employeeTz);
        
        // Handle overnight shift
        if ($shiftEnd->lt($shiftStart)) {
            $shiftEnd->addDay();
        }
        
        // Store timestamps for JavaScript
        $shiftStartTimestamp = $shiftStart->timestamp;
        $shiftEndTimestamp = $shiftEnd->timestamp;
        
        // Check if employee has clocked in/out for this shift
        $attendanceForShift = Attendance::where('employee_id', $employee->id)
            ->where('date', $shiftDate)
            ->first();
        
        // Determine status and clock-in availability
        $status = 'upcoming';
        $secondsUntil = null;
        
        // Check if shift is completed (end time passed)
        if ($now->gt($shiftEnd)) {
            $isShiftCompleted = true;
            $status = 'completed';
            $canClockIn = false;
        }
        // Check if already clocked out
        elseif ($attendanceForShift && $attendanceForShift->clock_out) {
            $status = 'completed';
            $canClockIn = false;
        }
        // Check if currently clocked in
        elseif ($attendanceForShift && $attendanceForShift->clock_in && !$attendanceForShift->clock_out) {
            $status = 'checked_in';
            $canClockIn = false;
        }
        // Check if shift is ongoing
        elseif ($now->between($shiftStart, $shiftEnd)) {
            $status = 'ongoing';
            $canClockIn = true;
        }
        // Check if shift is upcoming
        elseif ($now->lt($shiftStart)) {
            $minutesUntilShift = $shiftStart->diffInMinutes($now);
            $secondsUntil = $now->diffInSeconds($shiftStart);
            
            // Allow clock-in only if within 10 minutes before shift start
            if ($minutesUntilShift <= 10) {
                $status = 'upcoming_ready';
                $canClockIn = true;
            } else {
                $status = 'upcoming';
                $canClockIn = false;
            }
        }
        else {
            $status = 'completed';
            $canClockIn = false;
        }
        
        $shiftInfo = [
            'status' => $status,
            'seconds_until' => $secondsUntil,
            'start_local' => $shiftStart,
            'end_local' => $shiftEnd,
            'slot_timezone' => $employeeTz,
            'shift_date' => $shiftDate,
        ];
    }
    
    return view('employee.dashboard.index', compact(
        'employee',
        'attendanceToday',
        'shiftInfo',
        'canClockIn',
        'shiftStartTimestamp',
        'shiftEndTimestamp',
        'isShiftCompleted'
    ));
}
    
    public function editProfile()
    {
        $employee = Employee::where('user_id', Auth::id())->first();
        return view('employee.profile.index', compact('employee'));
    }

    public function updateProfile(Request $request)
    {
        $employee = Employee::where('user_id', Auth::id())->firstOrFail();

        $request->validate([
            'field' => 'required|string',
        ]);

        $field = $request->field;

        if ($field === 'upload_documents' && $request->hasFile('value')) {
            $path = $request->file('value')->store("employees/documents/{$employee->id}", 'public');
        
            $docs = $employee->upload_documents ?? [];
        
            if (!is_array($docs)) {
                $docs = json_decode($docs, true) ?? [];
            }
        
            $docs[] = $path;
        
            $employee->upload_documents = $docs;
            $employee->profile_completed = true;
            $employee->save();
        
            return response()->json([
                'success' => true,
                'value'   => asset('storage/' . $path),
            ]);
        }

        if ($field === 'profile_picture' && $request->hasFile('value')) {
            $path = $request->file('value')->store("employees/profile_pictures/{$employee->id}", 'public');
            $employee->update(['profile_picture' => $path]);

            return response()->json([
                'success' => true,
                'value'   => asset('storage/' . $path),
            ]);
        }

        if (!Schema::hasColumn('employees', $field)) {
            return response()->json(['success' => false, 'message' => 'Invalid field'], 422);
        }

        $employee->update([$field => $request->value]);

        return response()->json(['success' => true]);
    }

    public function delete_document(Request $request)
    {
        $employee = Employee::where('user_id', Auth::id())->firstOrFail();

        $path = $request->path;

        $docs = $employee->upload_documents ?? [];
        if (!is_array($docs)) {
            $docs = json_decode($docs, true) ?? [];
        }

        $docs = array_filter($docs, function ($doc) use ($path) {
            return $doc !== $path;
        });

        $employee->upload_documents = array_values($docs);
        $employee->save();

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        return response()->json(['success' => true]);
    }

public function employee_attendance(Request $request)
{
    if ($request->ajax()) {
        $employee = Employee::where('user_id', auth()->id())->firstOrFail();
        $employeeTz = $employee->timezone ?? 'Asia/Karachi';

        $query = Attendance::with(['employee', 'breaks'])
            ->where('employee_id', $employee->id);

        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('date', [$request->from_date, $request->to_date]);
        } elseif ($request->filled('from_date')) {
            $query->whereDate('date', '>=', $request->from_date);
        } elseif ($request->filled('to_date')) {
            $query->whereDate('date', '<=', $request->to_date);
        }

        return DataTables::eloquent($query)
            ->addIndexColumn()
            ->addColumn('name', function ($row) {
                $firstName = $row->employee->first_name ?? '';
                $lastName  = $row->employee->last_name ?? '';
                return trim($firstName . ' ' . $lastName) ?: 'N/A';
            })
            ->addColumn('date', function ($row) {
                return $row->date ? Carbon::parse($row->date)->format('Y-m-d') : '-';
            })
            ->addColumn('shift_time', function ($row) use ($employee) {
                // Get the shift for this specific attendance date
                $attendanceDate = Carbon::parse($row->date);
                $weekday = strtolower($attendanceDate->format('l'));
                
                // Get the schedule for that weekday
                $schedule = EmployeeClientSchedule::where('employee_id', $employee->id)
                    ->where('weekday', $weekday)
                    ->where('enabled', true)
                    ->first();
                
                if ($schedule) {
                    // Parse the shift times
                    $startTime = Carbon::parse($schedule->start_time);
                    $endTime = Carbon::parse($schedule->end_time);
                    
                    return $startTime->format('h:i A') . ' - ' . $endTime->format('h:i A');
                }
                
                // If no schedule found, check if it's an overnight shift from previous day
                // Try to find schedule from previous day
                $prevDay = $attendanceDate->copy()->subDay();
                $prevWeekday = strtolower($prevDay->format('l'));
                
                $prevSchedule = EmployeeClientSchedule::where('employee_id', $employee->id)
                    ->where('weekday', $prevWeekday)
                    ->where('enabled', true)
                    ->first();
                
                if ($prevSchedule) {
                    $startTime = Carbon::parse($prevSchedule->start_time);
                    $endTime = Carbon::parse($prevSchedule->end_time);
                    
                    // Check if this is an overnight shift (end time is next day)
                    if ($endTime->lt($startTime) || $endTime->format('H:i') < $startTime->format('H:i')) {
                        return $startTime->format('h:i A') . ' - ' . $endTime->format('h:i A') . ' (next day)';
                    }
                }
                
                return '-';
            })
            ->addColumn('clock_in', function ($row) use ($employeeTz) {
                if (!$row->clock_in) {
                    return '-';
                }
                return Carbon::parse($row->clock_in)
                    ->timezone($employeeTz)
                    ->format('h:i A');
            })
            ->addColumn('clock_out', function ($row) use ($employeeTz) {
                if (!$row->clock_out) {
                    return '-';
                }
                return Carbon::parse($row->clock_out)
                    ->timezone($employeeTz)
                    ->format('h:i A');
            })
            ->addColumn('breaks', function ($row) {
                return $row->breaks->count();
            })
            ->addColumn('overtime', function ($row) {
                if (($row->overtime ?? 0) > 0) {
                    $hours = floor($row->overtime / 60);
                    $minutes = $row->overtime % 60;
                    return sprintf('%d:%02d hrs', $hours, $minutes);
                }
                return '-';
            })
            ->addColumn('worked_hours', function ($row) {
                if ($row->clock_in && !$row->clock_out) {
                    return 'Running...';
                }
                if ($row->clock_in && $row->clock_out) {
                    $start = Carbon::parse($row->clock_in);
                    $end   = Carbon::parse($row->clock_out);
                    $minutes = (int) $start->diffInMinutes($end);
                    if ($minutes < 60) {
                        return $minutes . ' mins';
                    }
                    return sprintf('%d:%02d hrs', floor($minutes / 60), $minutes % 60);
                }
                return '-';
            })
            ->addColumn('actions', function ($row) {
                return '<button class="btn btn-sm btn-warning request-change" 
                    data-id="'.$row->id.'"
                    data-date="'.Carbon::parse($row->date)->format('Y-m-d').'"
                    data-clock_in="'.$row->clock_in.'"
                    data-clock_out="'.$row->clock_out.'">
                    Request Change
                </button>';
            })
            ->rawColumns(['worked_hours', 'actions'])
            ->make(true);
    }

    return view('employee.attendance.index');
}

    public function settings(Request $request)
    {
        $employee = Employee::where('user_id', auth()->user()->id)->first();
        return view('employee.setting.index', compact('employee'));
    }

    public function change_password(Request $request)
    {
        $employee = Employee::where('user_id', auth()->user()->id)->first();

        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $employee->user->update([
            'password' => Hash::make($request->password),
        ]);
        $employee->password = Hash::make($request->password);
        $employee->save();

        return back()->with('success', 'Password updated successfully!');
    }

    public function my_schedule(Request $request)
    {
        $employee = Employee::where('user_id', Auth::id())->firstOrFail();
        $employeeTz = $employee->timezone ?? 'Asia/Karachi';
        
        $schedules = $employee->clientSchedules()
            ->with('client')
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();
            
        foreach ($schedules as $schedule) {
            $schedule->start_time_display = Carbon::parse($schedule->start_time, $employeeTz)->format('h:i A');
            $schedule->end_time_display = Carbon::parse($schedule->end_time, $employeeTz)->format('h:i A');
        }
            
        return view('employee.schedule.index', compact('schedules'));
    }
}