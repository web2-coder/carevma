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
    private const EMAIL_DOMAIN = '@carevma-seed.test';
    private const BREAK_TAG = 'carevma-calculated-demo-v2';

    public function run(): void
    {
        DB::transaction(function () {
            $this->removePreviousDemoData();

            $lookups = $this->lookups();
            $employees = $this->employees($lookups);
            $clients = $this->clients($lookups);
            $schedules = $this->assignSchedules($employees, $clients);
            $this->seedAttendance($employees, $schedules);
        });

        $this->command?->info('CareVMA calculated demo data seeded: 5 VAs, 5 Doctors, repeating schedules and timestamp-derived attendance.');
        $this->command?->info('Worked minutes are calculated from clock-in/out + breaks. Regular work is capped by the scheduled shift less allowed break; only approved overtime is additionally payable.');
    }

    private function removePreviousDemoData(): void
    {
        $emails = array_merge(
            array_map(fn ($n) => "seed.va{$n}".self::EMAIL_DOMAIN, range(1, 5)),
            array_map(fn ($n) => "seed.doctor{$n}".self::EMAIL_DOMAIN, range(1, 5)),
        );

        // Deleting only the known seed users is intentional. Employee/client children cascade
        // through the application's existing foreign keys (attendance, schedules, rates, invoices, etc.).
        User::query()->whereIn('email', $emails)->delete();
    }

    private function lookups(): array
    {
        return [
            'department' => Department::firstOrCreate(['name' => 'Seed Demo Operations'], ['description' => 'CareVMA calculated demo data']),
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
            $email = 'seed.va'.($i + 1).self::EMAIL_DOMAIN;
            $user = User::create([
                'email' => $email,
                'name' => "$first $last",
                'password' => $password,
                'role' => 'employee',
                'last_seen_at' => null,
            ]);

            $employee = Employee::create([
                'user_id' => $user->id,
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
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
            ]);

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
            $email = 'seed.doctor'.($i + 1).self::EMAIL_DOMAIN;
            $user = User::create([
                'email' => $email,
                'name' => $name,
                'password' => $password,
                'role' => 'client',
                'last_seen_at' => null,
            ]);

            $result[] = Client::create([
                'user_id' => $user->id,
                'name' => $name,
                'email' => $email,
                'business_name' => $business,
                'country' => 'United States',
                'phone_number' => '+1 555 81'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'per_hour_charges' => $charge,
                'break_allowed_hours' => 1,
                'timezone' => $timezone,
                'contract_type_id' => $l['contract']->id,
                'service_id' => $l['service']->id,
                'ring_center' => false,
            ]);
        }

        return $result;
    }

    private function assignSchedules(array $employees, array $clients): array
    {
        $weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $byEmployee = [];

        foreach ($employees as $i => $entry) {
            $employee = $entry['model'];
            // Each VA gets one or two doctors. The pattern is deterministic so reruns are testable.
            $doctorIndexes = $i % 2 === 0
                ? [($i * 3 + 2) % 5]
                : array_values(array_unique([$i % 5, ($i * 2 + 1) % 5]));

            foreach ($doctorIndexes as $slot => $doctorIndex) {
                $client = $clients[$doctorIndex];
                $rate = round($entry['base_rate'] + ($doctorIndex * 1.35) + ($slot * 0.75), 2);

                ClientEmployeeRate::create([
                    'client_id' => $client->id,
                    'employee_id' => $employee->id,
                    'per_hour_rate' => $rate,
                ]);

                foreach ($weekdays as $dayIndex => $weekday) {
                    $startHour = 8 + (($doctorIndex + $i + $dayIndex) % 3);
                    $start = sprintf('%02d:00:00', $startHour);
                    $end = sprintf('%02d:00:00', $startHour + 9); // 9h span, 1h allowed break => max 8h regular

                    $schedule = EmployeeClientSchedule::create([
                        'employee_id' => $employee->id,
                        'client_id' => $client->id,
                        'weekday' => $weekday,
                        'schedule_date' => null,
                        'start_time' => $start,
                        'end_time' => $end,
                        'timezone' => $client->timezone,
                        'no_of_hours' => '8.00',
                        'break_allowed_hours' => 1.00,
                        'enabled' => true,
                        'repeat' => true,
                    ]);
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
            $historyDays = 15 + (($employeeIndex * 4 + 7) % 16); // 15..30 calendar-day history
            $startDate = now()->subDays($historyDays)->startOfDay();
            $endDate = now()->subDay()->startOfDay();

            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                $matching = collect($schedulesByEmployee[$employee->id] ?? [])
                    ->filter(fn (EmployeeClientSchedule $s) => $s->weekday === $date->format('l'))
                    ->values();

                foreach ($matching as $schedule) {
                    $seed = ($employee->id * 97) + ($schedule->id * 31) + ((int) $date->format('z') * 17);

                    // Some scheduled days are absent. No attendance means no payable time.
                    if (($seed % 25) < 2) {
                        continue;
                    }

                    $timezone = $schedule->client->timezone ?? $schedule->timezone ?? 'UTC';
                    $shiftStart = Carbon::parse($date->toDateString().' '.$schedule->start_time, $timezone)->startOfMinute();
                    $shiftEnd = Carbon::parse($date->toDateString().' '.$schedule->end_time, $timezone)->startOfMinute();
                    if ($shiftEnd->lte($shiftStart)) {
                        $shiftEnd->addDay();
                    }

                    // Random-looking scenarios are created ONLY by timestamps/breaks.
                    // AttendanceBillingService derives late, regular worked minutes and overtime.
                    $scenario = $seed % 7;
                    $lateMinutes = in_array($scenario, [1, 4], true) ? 10 + ($seed % 51) : 0;
                    $earlyArrivalMinutes = $scenario === 0 ? 5 + ($seed % 11) : 0;
                    $earlyLeaveMinutes = $scenario === 2 ? 20 + ($seed % 101) : 0;
                    $afterShiftMinutes = in_array($scenario, [3, 5, 6], true) ? 20 + ($seed % 101) : 0;

                    $clockIn = $shiftStart->copy()->addMinutes($lateMinutes)->subMinutes($earlyArrivalMinutes);
                    $clockOut = $shiftEnd->copy()->subMinutes($earlyLeaveMinutes)->addMinutes($afterShiftMinutes);
                    if ($clockOut->lte($clockIn)) {
                        $clockOut = $clockIn->copy()->addHours(4);
                    }

                    // 1h is allowed. Taking less does NOT increase regular billing above the 8h cap.
                    // Taking more reduces actual worked time because the whole real break is deducted.
                    $breakMinutes = match ($scenario) {
                        4 => 75 + ($seed % 31), // long break
                        6 => 30 + ($seed % 21), // short break; extra post-shift time may become OT
                        default => 45 + ($seed % 16),
                    };

                    $breakStart = $clockIn->copy()->addHours(3);
                    if ($breakStart->copy()->addMinutes($breakMinutes)->gte($clockOut)) {
                        $breakStart = $clockIn->copy()->addMinutes(60);
                    }
                    $breakEnd = $breakStart->copy()->addMinutes($breakMinutes);

                    $attendance = Attendance::create([
                        'employee_id' => $employee->id,
                        'client_id' => $schedule->client_id,
                        'schedule_id' => $schedule->id,
                        'date' => $date->toDateString(),
                        'timezone_snapshot' => $timezone,
                        'shift_start_snapshot' => $shiftStart->copy()->utc(),
                        'shift_end_snapshot' => $shiftEnd->copy()->utc(),
                        'clock_in' => $clockIn->copy()->utc(),
                        'clock_out' => $clockOut->copy()->utc(),
                        'break_limit' => '01:00:00',
                        'break_taken' => 0,
                    ]);

                    EmployeeBreak::create([
                        'attendance_id' => $attendance->id,
                        'start_time' => $breakStart->copy()->utc(),
                        'end_time' => $breakEnd->copy()->utc(),
                        'notes' => self::BREAK_TAG,
                    ]);

                    // Store actual break seconds, then let the SAME production billing service calculate
                    // total_minutes, late_minutes and overtime from the timestamps.
                    $attendance->break_taken = $breakMinutes * 60;
                    $attendance->save();
                    $attendance = $billing->recalculate($attendance);

                    // Only approval state is seeded after calculation. Never invent overtime minutes.
                    if ((int) $attendance->overtime > 0) {
                        $approvalMode = $seed % 3;
                        if ($approvalMode === 0) {
                            $attendance->overtime_status = 'approved';
                            $attendance->approved_overtime_minutes = (int) $attendance->overtime;
                            $attendance->overtime_approved_at = now();
                        } elseif ($approvalMode === 1) {
                            $attendance->overtime_status = 'review_required';
                            $attendance->approved_overtime_minutes = 0;
                        } else {
                            $attendance->overtime_status = 'rejected';
                            $attendance->approved_overtime_minutes = 0;
                        }
                        $attendance->save();
                    }
                }
            }
        }
    }
}
