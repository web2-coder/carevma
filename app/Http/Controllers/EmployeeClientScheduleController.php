<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\EmployeeClientSchedule;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Client;
use App\Models\Attendance;
use App\Models\ClientEmployeeRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class EmployeeClientScheduleController extends Controller
{

    public function store(Request $request)
{
    $request->validate([
        'client_id' => 'required|exists:clients,id',
        'timezone.*' => 'required',
        'employees.*.employee_id' => 'required|exists:employees,id',
        'repeat' => 'boolean',
        'enabled' => 'boolean',
        'employees.*.per_hour_rate' => 'required|numeric|min:0',
    ]);

    $employees = $request->input('employees', []);
    $clientId  = $request->client_id;

    $client = Client::findOrFail($clientId);
    $clientTimezone = $client->timezone ?? config('carevma.business_timezone', 'Asia/Karachi'); 

    DB::beginTransaction();

    try {
        foreach ($employees as $employee) {
            $employeeId = $employee['employee_id'];

                $rate = $employee['per_hour_rate'];

                ClientEmployeeRate::updateOrCreate(
                    [
                        'client_id' => $clientId,
                        'employee_id' => $employeeId
                    ],
                    [
                        'per_hour_rate' => $rate
                    ]
                );

            foreach ($employee['weekdays'] as $day => $data) {
                if (!isset($data['enabled'])) {
                    continue;
                }

                $exists = EmployeeClientSchedule::where('client_id', $clientId)
                    ->where('employee_id', $employeeId)
                    ->where('weekday', $day)
                    ->exists();

                if ($exists) {
                    DB::rollBack();
                    return redirect()->back()
                        ->withErrors(["error" => "Schedule for {$day} already exists for this employee and client."])
                        ->withInput();
                }

                // 🕒 timezone for this slot
                $slotTimezone = $data['timezone'] ?? $clientTimezone;

                $startTime = !empty($data['start'])
                    ? Carbon::parse($data['start'], $slotTimezone)->format('H:i:s')
                    : null;

                $endTime = !empty($data['end'])
                    ? Carbon::parse($data['end'], $slotTimezone)->format('H:i:s')
                    : null;

                EmployeeClientSchedule::create([
                    'enabled'     => $data['enabled'] ?? false,
                    'employee_id' => $employeeId,
                    'client_id'   => $clientId,
                    'weekday'     => $day,
                    'start_time'  => $startTime,
                    'end_time'    => $endTime,
                    'no_of_hours' => $data['total_hours'] ?? null,
                    'repeat'      => $data['repeat'] ?? false,
                    'timezone'    => $slotTimezone, // <-- 📌 added here
                ]);
            }
        }

        DB::commit();

        return redirect()->back()->with('success', 'Schedules assigned successfully.');

    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('Schedule operation failed.', ['exception' => $e]);
        return redirect()->back()->withErrors(['error' => 'The schedule could not be saved. Please review the selected employee, client, timezone, and times, then try again.']);
    }
}


    public function update(Request $request)
    {
        $request->validate([
            'start_time'  => 'required|date_format:H:i',
            'end_time'    => 'required|date_format:H:i|after:start_time',
            'no_of_hours' => 'nullable|numeric|min:0'
        ]);

        $schedule = EmployeeClientSchedule::findOrFail($request->id);

        $client = Client::findOrFail($schedule->client_id);
        $tz     = $client->timezone ?? config('carevma.business_timezone', 'Asia/Karachi');

        $schedule->start_time  = Carbon::parse($request->start_time, $tz)
            ->format('H:i:s');

        $schedule->end_time    = Carbon::parse($request->end_time, $tz)
            ->format('H:i:s');

        $schedule->no_of_hours = $request->no_of_hours;

        $schedule->save();

        return redirect()->back()->with('success', 'Schedule updated successfully.');
    }

    public function destroy($id)
    {
        try {
            $schedule = EmployeeClientSchedule::findOrFail($id);
            $schedule->delete();

            return redirect()->back()->with('success', 'Schedule deleted successfully.');
        } catch (\Exception $e) {
            \Log::error('Schedule deletion failed.', ['exception' => $e]);
            return redirect()->back()->withErrors(['error' => 'The schedule could not be deleted. It may be linked to attendance or another protected record.']);
        }
    }

public function generate(Request $request)
{
    $request->validate([
        'client_id' => 'required|exists:clients,id',
        'date_from' => 'required|date',
        'date_to'   => 'required|date|after_or_equal:date_from',
    ]);

    $clientId = $request->client_id;
    $start    = Carbon::parse($request->date_from)->startOfDay();
    $end      = Carbon::parse($request->date_to)->endOfDay();

    $client = Client::findOrFail($clientId);

    // Fetch all employees linked with this client
    $employees = Employee::whereHas('clients', function ($q) use ($clientId) {
        $q->where('client_id', $clientId);
    })->get();

    if ($employees->isEmpty()) {
        return response()->json([
            'error' => 'No employees found for this client.'
        ], 422);
    }

    $results = [];

    $rates = ClientEmployeeRate::where('client_id', $clientId)
        ->pluck('per_hour_rate', 'employee_id');

    foreach ($employees as $employee) {
        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start, $end])
            ->get();

        if ($attendance->isEmpty()) {
            continue;
        }

        $regularMinutes  = 0;
        $overtimeMinutes = 0;
        $totalLate       = 0;

        foreach ($attendance as $record) {
            $workedMinutes = (int) ($record->total_minutes ?? 0);

            $dailyRegularMinutes  = min($workedMinutes, 480);
            $dailyOvertimeMinutes = max($workedMinutes - 480, 0);

            $regularMinutes  += $dailyRegularMinutes;
            $overtimeMinutes += $dailyOvertimeMinutes;

            $totalLate += (int) ($record->break_taken ?? 0);
        }

        $regularHours  = round($regularMinutes / 60, 2);
        $overtimeHours = round($overtimeMinutes / 60, 2);
        $totalHours    = round($regularHours + $overtimeHours, 2);

        $rate = (float) ($rates[$employee->id] ?? 0);

        // Salary only on regular shift hours
        $salaryAmount = round($regularHours * $rate, 2);

        $results[] = [
            'employee_id'      => $employee->id,
            'employee_name'    => $employee->first_name . ' ' . $employee->last_name,
            'regular_hours'    => $regularHours,
            'overtime_hours'   => $overtimeHours,
            'total_hours'      => $totalHours,
            'total_late'       => $totalLate,
            'total_overtime'   => $overtimeMinutes, // in minutes
            'salary_amount'    => $salaryAmount,
            'period_start'     => $request->date_from,
            'period_end'       => $request->date_to,
        ];
    }

    return response()->json([
        'client_name' => $client->name,
        'employees'   => $results
    ]);
}
    

    
}
