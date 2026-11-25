<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeClientSchedule;
use App\Models\User;
use App\Services\TrustedTimeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompletedShiftNextScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_occurrence_is_not_returned_as_the_next_shift(): void
    {
        $this->assertSame('carevma_test', DB::connection()->getDatabaseName());

        $instant = Carbon::parse('2026-11-20 15:00:00', 'UTC');
        $clock = \Mockery::mock(TrustedTimeService::class);
        $clock->shouldReceive('now')->andReturnUsing(
            fn (string $timezone = 'UTC') => $instant->copy()->setTimezone($timezone)
        );
        $clock->shouldReceive('source')->andReturn('test');
        $this->app->instance(TrustedTimeService::class, $clock);

        $user = User::create([
            'name' => 'Test VA', 'email' => 'completed-next-va@carevma.test',
            'password' => 'password', 'role' => 'employee',
        ]);
        $employee = Employee::create([
            'user_id' => $user->id,
            'first_name' => 'Test', 'last_name' => 'VA',
            'email' => 'completed-next-va@carevma.test',
            'password' => 'hashed', 'timezone' => 'UTC',
            'profile_completed' => true,
            'phone' => '03001234567',
            'date_of_birth' => '1995-06-15',
            'gender' => 'Male',
            'marital_status' => 'Single',
        ]);

        foreach ([false, true] as $repeat) {
            $doctorName = $repeat ? 'Weekly Doctor' : 'One Time Doctor';
            $doctorEmail = $repeat ? 'weekly-next@carevma.test' : 'once-next@carevma.test';
            $doctorUser = User::create([
                'name' => $doctorName,
                'email' => $doctorEmail,
                'password' => 'password',
                'role' => 'client',
            ]);
            $client = Client::create([
                'user_id' => $doctorUser->id,
                'name' => $doctorName,
                'email' => $doctorEmail,
                'timezone' => 'UTC', 'per_hour_charges' => 10,
            ]);
            $schedule = EmployeeClientSchedule::create([
                'client_id' => $client->id,
                'employee_id' => $employee->id,
                'weekday' => 'Friday',
                'schedule_date' => '2026-11-20',
                'start_time' => '09:00:00', 'end_time' => '17:00:00',
                'timezone' => 'UTC', 'no_of_hours' => 8,
                'enabled' => true, 'repeat' => $repeat,
            ]);
            Attendance::create([
                'employee_id' => $employee->id,
                'client_id' => $client->id,
                'schedule_id' => $schedule->id,
                'date' => '2026-11-20',
                'clock_in' => '2026-11-20 09:00:00',
                'clock_out' => '2026-11-20 10:24:00',
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/employee/dashboard')->assertOk();
        $next = collect($response->json('next_schedules'));

        $this->assertCount(1, $next, 'Completed one-time shifts must not appear as upcoming.');
        $this->assertSame('Weekly Doctor', data_get($next->first(), 'client.name'));
        $this->assertStringStartsWith('2026-11-27', (string) data_get($next->first(), 'client_shift_starts_at'));
    }
}
