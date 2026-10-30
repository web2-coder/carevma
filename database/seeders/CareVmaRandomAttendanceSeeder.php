<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\ClientEmployeeRate;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeBreak;
use App\Models\EmployeeClientSchedule;
use App\Models\EmployeeStatus;
use App\Models\EmploymentType;
use App\Models\Expertise;
use App\Models\ReportingManager;
use App\Models\Role;
use App\Models\Service;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\AttendanceBillingService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CareVmaRandomAttendanceSeeder extends Seeder
{
    private const TAG = 'seed-random-demo';

    public function run(): void
    {
        DB::transaction(function () {
            $lookups = $this->lookups();
            $employees = $this->employees($lookups);
            $clients = $this->clients($lookups);
            $schedules = $this->assignSchedules($employees, $clients);
            $this->seedAttendance($employees, $schedules);
        });

        $this->command?->info('CareVMA random demo data seeded: 5 VAs, 5 Doctors, weekly schedules and 15-30 days of attendance.');
        $this->command?->warn('Demo logins use Password123! and @carevma-seed.test addresses. Do not use these accounts as real users.');
    }

    private function lookups(): array
    {
        return [
            'department' => Department::firstOrCreate(['name' => 'Seed Demo Operations'], ['description' => 'CareVMA random demo data']),
            'role' => Role::firstOrCreate(['name' => 'Virtual Assistant']),
            'employment' => EmploymentType::firstOrCreate(['name' => 'Full Time']),
            'shift' => ShiftType::firstOrCreate(['name' => 'Day Shift']),
            'designation' => Designation::firstOrCreate(['name' => 'Virtual Assistant']),
            'status' => EmployeeStatus::firstOrCreate(['name' => 'Active']),
            'expertise' => Expertise::firstOrCreate(['name' => 'General Administration']),
            'manager' => ReportingManager::firstOrCreate(['name' => 'Seed Demo Manager']),
            'service' => Service::firstOrCreate(['name' => 'Medical VA Service']),
            'contract' => ContractType::firstOrCreate(['name' => 'Hourly']),
        ];
    }

    private function employees(array $l): array
    {
        $people = [
            ['Ava', 'Morgan', 'Asia/Karachi', 'Lahore', 12.50],
            ['Noah', 'Reed', 'Asia/Karachi', 'Lahore', 14.00],
            ['Mia', 'Carter', 'Asia/Karachi', 'Islamabad', 15.75],
            ['Ethan', 'Brooks', 'Asia/Karachi', 'Rawalpindi', 17.25],
            ['Sofia', 'Bennett', 'Asia/Karachi', 'Lahore', 19.50],
        ];

        $password = Hash::make('Password123!');
        $result = [];

        foreach ($people as $i => [$first, $last, $timezone, $location, $baseRate]) {
            $email = 'seed.va'.($i + 1).'@carevma-seed.test';
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => "$first $last", 'password' => $password, 'role' => 'employee', 'last_seen_at' => null]
            );

            $employee = Employee::updateOrCreate(
                ['email' => $email],
                [
                    'user_id' => $user->id,
                    'first_name' => $first,
                    'last_name' => $last,
                    'password' => $password,
                    'phone' => '+1 555 71'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'department_id' => $l['department']->id,
                    'role_id' => $l['role']->id,
                    'employment_type_id' => $l['employment']->id,
                    'shift_type_id' => $l['shift']->id,
                    'designation_id' => $l['designation']->id,
                    'employee_status_id' => $l['status']->id,
                    'expertise_id' => $l['expertise']->id,
                    'reporting_manager_id' => $l['manager']->id,
                    'location' => $location,
                    'timezone' => $timezone,
                    'break_allowed_hours' => 1,
                    'profile_completed' => true,
                    'salary_usd' => $baseRate,
                    'date_of_joining' => now()->subMonths(6)->toDateString(),
                ]
            );

            $result[] = ['model' => $employee, 'base_rate' => $baseRate];
        }

        return $result;
    }

    private function clients(array $l): array
    {
        $doctors = [
            ['Dr. Alex Green', 'Green Family Medicine', 'America/New_York', 22.00],
            ['Dr. Emma Stone', 'Stone Primary Care', 'America/Chicago', 24.50],
            ['Dr. Liam Parker', 'Parker Medical Group', 'America/Denver', 27.00],
            ['Dr. Olivia Harris', 'Harris Wellness Clinic', 'America/Los_Angeles', 29.50],
            ['Dr. James Wilson', 'Wilson Health Center', 'America/New_York', 32.00],
        ];

        $password = Hash::make('Password123!');
        $result = [];

        foreach ($doctors as $i => [$name, $business, $timezone, $charge]) {
            $email = 'seed.doctor'.($i + 1).'@carevma-seed.test';
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => $password, 'role' => 'client', 'last_seen_at' => null]
            );

            $result[] = Client::updateOrCreate(
                ['email' => $email],
                [
                    'user_id' => $user->id,
                    'name' => $name,
                    'business_name' => $business,
                    'country' => 'United States',
                    'phone_number' => '+1 555 81'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'per_hour_charges' => $charge,
                    'break_allowed_hours' => 1,
                    'timezone' => $timezone,
                    'contract_type_id' => $l['contract']->id,
                    'service_id' => $l['service']->id,
                    'ring_center' => false,
                ]
            );
        }

        return $result;
    }

    private function assignSchedules(array $employees, array $clients): array
    {
        $weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $byEmployee = [];

        foreach ($employees as $i => $entry) {
            $employee = $entry['model'];
            // Deterministic "random-looking" assignment: each VA gets 1-2 doctors, spread across all five doctors.
            $doctorIndexes = array_values(array_unique([$i % 5, ($i * 2 + 1) % 5]));
            if ($i % 2 === 0) {
                $doctorIndexes = [($i * 3 + 2) % 5];
            }

            foreach ($doctorIndexes as $slot => $doctorIndex) {
                $client = $clients[$doctorIndex];
                $rate = round($entry['base_rate'] + ($doctorIndex * 1.35) + ($slot * 0.75), 2);
                ClientEmployeeRate::updateOrCreate(
                    ['client_id' => $client->id, 'employee_id' => $employee->id],
                    ['per_hour_rate' => $rate]
                );

                foreach ($weekdays as $dayIndex => $weekday) {
                    $startHour = 8 + (($doctorIndex + $i + $dayIndex) % 3); // 08:00, 09:00 or 10:00 doctor-local
                    $start = sprintf('%02d:00:00', $startHour);
                    $end = sprintf('%02d:00:00', $startHour + 9); // 9h span incl. 1h break => 8 billable

                    $schedule = EmployeeClientSchedule::updateOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'client_id' => $client->id,
                            'weekday' => $weekday,
                            'schedule_date' => null,
                        ],
                        [
                            'start_time' => $start,
                            'end_time' => $end,
                            'timezone' => $client->timezone,
                            'no_of_hours' => '8.00',
                            'break_allowed_hours' => 1.00,
                            'enabled' => true,
                            'repeat' => true,
                        ]
                    );
                    $byEmployee[$employee->id][] = $schedule;
                }
            }
        }

        return $byEmployee;
    }

    private function seedAttendance(array $employees, array $schedulesByEmployee): void
    {
        $billing = app(AttendanceBillingService::class);

        foreach ($employees as $employeeIndex => $entry) {
            $employee = $entry['model'];
            $historyDays = 15 + (($employeeIndex * 4 + 7) % 16); // deterministic 15..30
            $startDate = now()->subDays($historyDays)->startOfDay();
            $endDate = now()->subDay()->startOfDay();

            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                $matching = collect($schedulesByEmployee[$employee->id] ?? [])
                    ->filter(fn (EmployeeClientSchedule $s) => $s->weekday === $date->format('l'))
                    ->values();

                foreach ($matching as $scheduleIndex => $schedule) {
                    $seed = ($employee->id * 97) + ($schedule->id * 31) + ((int) $date->format('z') * 17);

                    // Roughly 8% scheduled absences; no fake attendance row is created for these days.
                    if (($seed % 25) < 2) {
                        continue;
                    }

                    $timezone = $schedule->client->timezone ?? $schedule->timezone ?? 'UTC';
                    $shiftStartLocal = Carbon::parse($date->toDateString().' '.$schedule->start_time, $timezone)->startOfMinute();
                    $shiftEndLocal = Carbon::parse($date->toDateString().' '.$schedule->end_time, $timezone)->startOfMinute();
                    if ($shiftEndLocal->lte($shiftStartLocal)) {
                        $shiftEndLocal->addDay();
                    }

                    $lateMinutes = (($seed % 10) < 3) ? (5 + ($seed % 41)) : 0;
                    $earlyMinutes = (($seed % 17) === 4) ? (10 + ($seed % 36)) : 0;
                    $overtimeMinutes = (($seed % 9) < 3) ? (15 + ($seed % 76)) : 0;
                    $earlyArrival = $lateMinutes === 0 ? ($seed % 11) : 0;

                    $clockInLocal = $shiftStartLocal->copy()->addMinutes($lateMinutes)->subMinutes($earlyArrival);
                    $clockOutLocal = $shiftEndLocal->copy()->subMinutes($earlyMinutes)->addMinutes($overtimeMinutes);
                    if ($clockOutLocal->lte($clockInLocal)) {
                        $clockOutLocal = $clockInLocal->copy()->addHours(4);
                    }

                    $breakMinutes = 30 + ($seed % 31); // 30..60 min
                    $breakStartLocal = $shiftStartLocal->copy()->addHours(3)->addMinutes($seed % 45);
                    if ($breakStartLocal->gte($clockOutLocal->copy()->subMinutes($breakMinutes + 5))) {
                        $breakStartLocal = $clockInLocal->copy()->addMinutes(90);
                    }
                    $breakEndLocal = $breakStartLocal->copy()->addMinutes($breakMinutes);

                    $attendance = Attendance::updateOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'schedule_id' => $schedule->id,
                            'date' => $date->toDateString(),
                        ],
                        [
                            'client_id' => $schedule->client_id,
                            'timezone_snapshot' => $timezone,
                            'shift_start_snapshot' => $shiftStartLocal->copy()->utc(),
                            'shift_end_snapshot' => $shiftEndLocal->copy()->utc(),
                            'clock_in' => $clockInLocal->copy()->utc(),
                            'clock_out' => $clockOutLocal->copy()->utc(),
                            'break_limit' => '01:00:00',
                            'break_taken' => $breakMinutes * 60,
                        ]
                    );

                    EmployeeBreak::updateOrCreate(
                        ['attendance_id' => $attendance->id, 'notes' => self::TAG],
                        [
                            'start_time' => $breakStartLocal->copy()->utc(),
                            'end_time' => $breakEndLocal->copy()->utc(),
                        ]
                    );

                    $billing->recalculate($attendance);

                    // Seed a realistic mixture of overtime workflow states without making unapproved OT billable.
                    if ((int) $attendance->overtime > 0) {
                        $mode = $seed % 4;
                        if ($mode === 0) {
                            $attendance->overtime_status = 'pending';
                        } elseif ($mode === 1) {
                            $attendance->overtime_status = 'approved';
                            $attendance->approved_overtime_minutes = (int) $attendance->overtime;
                        } elseif ($mode === 2) {
                            $attendance->overtime_status = 'rejected';
                            $attendance->approved_overtime_minutes = 0;
                        } else {
                            $attendance->overtime_status = 'review_required';
                        }
                        $attendance->save();
                    }
                }
            }
        }
    }
}
