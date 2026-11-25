<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeClientSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\WorkSessionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecurringWorkSessionOccurrencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_va_two_overlapping_doctors_and_repeat_week_have_distinct_idempotent_attendance(): void
    {
        $this->assertSame('carevma_test', DB::connection()->getDatabaseName());

        $vaUser = User::create([
            'name' => 'Recurring VA', 'email' => 'recurring-va@carevma.test',
            'role' => 'employee', 'password' => 'password',
        ]);
        $va = Employee::create([
            'user_id' => $vaUser->id, 'first_name' => 'Recurring', 'last_name' => 'VA',
            'email' => 'recurring-va@carevma.test', 'password' => 'hashed',
            'timezone' => 'UTC',
        ]);

        $schedules = [];
        foreach ([['Doctor One', '08:00:00', '17:00:00', '9.00'], ['Doctor Two', '11:00:00', '18:00:00', '7.00']] as $i => [$name, $from, $to, $hours]) {
            $email = 'recurring-doc-'.($i + 1).'@carevma.test';
            $doctorUser = User::create([
                'name' => $name, 'email' => $email, 'role' => 'client', 'password' => 'password',
            ]);
            $doctor = Client::create([
                'user_id' => $doctorUser->id, 'name' => $name, 'email' => $email,
                'timezone' => 'UTC', 'per_hour_charges' => 10,
            ]);
            $schedules[] = EmployeeClientSchedule::create([
                'employee_id' => $va->id, 'client_id' => $doctor->id,
                'weekday' => 'Friday', 'repeat' => true,
                'start_time' => $from, 'end_time' => $to, 'no_of_hours' => $hours,
                'timezone' => 'UTC', 'break_allowed_hours' => 0,
                'enabled' => true,
            ]);
        }

        $session = WorkSession::create([
            'employee_id' => $va->id,
            'primary_schedule_id' => $schedules[0]->id,
            'clock_in' => Carbon::parse('2026-11-20 07:55:00', 'UTC'),
            'status' => 'active',
        ]);
        $service = app(WorkSessionService::class);

        $service->reconcile($session, Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        $this->assertSame(2, Attendance::where('work_session_id', $session->id)->count());
        $this->assertSame(1, Attendance::where('work_session_id', $session->id)
            ->where('schedule_id', $schedules[0]->id)->count());

        // Same VA session continues to the next weekly Friday, when both
        // Doctors return. Each must have an independent new occurrence.
        $service->reconcile($session, Carbon::parse('2026-11-27 12:00:00', 'UTC'));
        $service->reconcile($session, Carbon::parse('2026-11-27 12:05:00', 'UTC'));
        $this->assertSame(4, Attendance::where('work_session_id', $session->id)->count());

        $d1 = Attendance::where('work_session_id', $session->id)
            ->where('schedule_id', $schedules[0]->id)
            ->orderBy('shift_start_snapshot')->get();
        $this->assertCount(2, $d1);
        $this->assertSame('2026-11-20', $d1[0]->date->toDateString());
        $this->assertSame('2026-11-27', $d1[1]->date->toDateString());
        $this->assertSame('2026-11-20 07:55:00', $d1[0]->clock_in->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-27 08:00:00', $d1[1]->clock_in->utc()->format('Y-m-d H:i:s'));

        $d2 = Attendance::where('work_session_id', $session->id)
            ->where('schedule_id', $schedules[1]->id)
            ->orderBy('shift_start_snapshot')->get();
        $this->assertCount(2, $d2);
        $this->assertSame('2026-11-27 11:00:00', $d2[1]->clock_in->utc()->format('Y-m-d H:i:s'));

        // The last shift ends at 18:00 on Friday; checkout is at 20:00.
        $closed = $service->reconcile($session, Carbon::parse('2026-11-27 20:01:00', 'UTC'));
        $this->assertSame('2026-11-27 20:00:00', $closed->clock_out->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(4, Attendance::where('work_session_id', $session->id)->count());
    }
}
