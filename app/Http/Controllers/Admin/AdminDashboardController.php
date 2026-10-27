<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Attendance;
use Carbon\Carbon;

class AdminDashboardController extends Controller
{
    public function index()
    {
       
        $clientsCount = Client::count();
        $employeesCount = Employee::count();

        $last30Days = Carbon::now()->subDays(30);

        $newClientsLast30 = Client::where('created_at', '>=', $last30Days)->count();

        $newEmployeesLast30 = Employee::where('created_at', '>=', $last30Days)->count();

        return view('admin.dashboard.index', compact(
            'clientsCount',
            'employeesCount',
            'newClientsLast30',
            'newEmployeesLast30'
        ));
    }

    // 🔹 Attendance Stats (Ajax ke liye)
public function getAttendanceStats()
{
    $checkedIn = Attendance::whereDate('clock_in', today())
        ->whereNotNull('clock_in')
        ->whereNull('clock_out')
        ->count();

    return response()->json([
        'checkedIn' => $checkedIn,
    ]);
}

  public function todayLiveUsers()
    {
        $today = Carbon::now()->toDateString();

        $employees = Employee::with(['attendances' => function ($query) use ($today) {
            $query->whereDate('date', $today);
        }])->get();

        $liveUsers = [];
        $checkedOutUsers = [];
        $offlineUsers = [];
        $lateUsers = [];

        foreach ($employees as $employee) {
            $attendance = $employee->attendances->first();

            if ($attendance) {
                if ($attendance->clock_in && !$attendance->clock_out) {
            
                    $liveUsers[] = $employee;

                    if ($attendance->clock_in && $attendance->clock_in->format('H:i:s') > "09:00:00") {
                        $lateUsers[] = $employee;
                    }
                } elseif ($attendance->clock_in && $attendance->clock_out) {
              
                    $checkedOutUsers[] = $employee;
                }
            } else {
          
                $offlineUsers[] = $employee;
            }
        }

        return view('admin.live_users', compact(
            'liveUsers',
            'checkedOutUsers',
            'offlineUsers',
            'lateUsers'
        ));
    }

  public function todayLiveUsersJson()
{
    $today = today(); // Laravel today() respects timezone

    $employees = Employee::with(['attendances' => function ($query) use ($today) {
        $query->whereDate('clock_in', $today);
    }])->get();

    $liveUsers = [];
    $checkedOutUsers = [];
    $offlineUsers = [];
    $lateUsers = [];

    foreach ($employees as $employee) {
        $attendance = $employee->attendances->first();

        if ($attendance) {
            $clockInReadable = $attendance->clock_in ? $attendance->clock_in->format('h:i A') : null;
            $clockOutReadable = $attendance->clock_out ? $attendance->clock_out->format('h:i A') : null;

            if ($attendance->clock_in && !$attendance->clock_out) {
                $liveUsers[] = [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'clock_in' => $clockInReadable,
                    'profile_picture' => $employee->profile_picture
                ];

                if ($attendance->clock_in->format('H:i:s') > "09:00:00") {
                    $lateUsers[] = [
                        'id' => $employee->id,
                        'full_name' => $employee->full_name,
                        'clock_in' => $clockInReadable,
                        'profile_picture' => $employee->profile_picture
                    ];
                }
            } elseif ($attendance->clock_in && $attendance->clock_out) {
                $checkedOutUsers[] = [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'clock_out' => $clockOutReadable,
                    'profile_picture' => $employee->profile_picture
                ];
            }
        } else {
            $offlineUsers[] = [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
            ];
        }
    }

    return response()->json([
        'liveUsers' => $liveUsers,
        'checkedOutUsers' => $checkedOutUsers,
        'offlineUsers' => $offlineUsers,
        'lateUsers' => $lateUsers,
    ]);
}

}