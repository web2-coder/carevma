<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeClientSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientDataScopeAndClockRaceTest extends TestCase
{
    use RefreshDatabase;

    /** Fields a Client/Doctor must never receive about a VA. */
    private const FORBIDDEN_EMPLOYEE_FIELDS = [
        'salary_pkr',
        'salary_usd',
        'date_of_birth',
        'age',
        'gender',
        'marital_status',
        'about_me_notes',
        'upload_documents',
        'password',
        'source_of_hire',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame(
            'carevma_test',
            $database,
            "Tests must run against carevma_test, not [{$database}]."
        );

        Mail::fake();
        config(['broadcasting.default' => 'null']);
    }

    /** A VA with a complete profile, so profile.complete does not intercept. */
    private function employee(): Employee
    {
        $user = User::create([
            'name' => 'VA One',
            'email' => 'va@carevma.test',
            'password' => 'password',
            'role' => 'employee',
        ]);

        return Employee::create([
            'user_id' => $user->id,
            'first_name' => 'VA',
            'last_name' => 'One',
            'email' => 'va@carevma.test',
            'password' => 'hashed',
            'phone' => '+10000000000',
            'date_of_birth' => '1990-05-04',
            'gender' => 'female',
            'marital_status' => 'single',
            'timezone' => 'UTC',
            'salary_pkr' => 250000,
            'salary_usd' => 900,
            'about_me_notes' => 'Internal HR note.',
            'profile_completed' => true,
        ]);
    }

    private function clientFor(Employee $employee): Client
    {
        $user = User::create([
            'name' => 'Doctor One',
            'email' => 'doctor@carevma.test',
            'password' => 'password',
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $user->id,
            'name' => 'Doctor One',
            'email' => 'doctor@carevma.test',
            'timezone' => 'UTC',
            'per_hour_charges' => 10,
        ]);

        EmployeeClientSchedule::create([
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'weekday' => 'monday',
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'no_of_hours' => 8,
            'timezone' => 'UTC',
            'enabled' => true,
        ]);

        return $client;
    }

    public function test_doctor_dashboard_does_not_expose_va_salary_or_personal_data(): void
    {
        $employee = $this->employee();
        $client = $this->clientFor($employee);

        $payload = $this->actingAs($client->user)
            ->getJson('/api/doctor/dashboard')
            ->assertOk()
            ->json('employees.0');

        $this->assertNotNull($payload, 'The assigned VA was not returned at all.');

        foreach (self::FORBIDDEN_EMPLOYEE_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $payload, "Doctor dashboard leaked [{$field}].");
        }

        // Still useful to the doctor.
        $this->assertSame('VA', $payload['first_name']);
        $this->assertSame('One', $payload['last_name']);
        $this->assertSame('UTC', $payload['timezone']);
    }

    public function test_doctor_attendance_view_does_not_expose_va_salary_or_personal_data(): void
    {
        $employee = $this->employee();
        $client = $this->clientFor($employee);

        $payload = $this->actingAs($client->user)
            ->getJson("/api/doctor/employees/{$employee->id}/attendance")
            ->assertOk()
            ->json('employee');

        $this->assertNotNull($payload);

        foreach (self::FORBIDDEN_EMPLOYEE_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $payload, "Doctor attendance view leaked [{$field}].");
        }

        $this->assertSame('VA', $payload['first_name']);
    }

    /**
     * Clock actions must go through the per-employee lock. Holding that lock from the
     * test stands in for a second in-flight request: without serialisation the
     * endpoint runs straight through and closes the session anyway.
     */
    public function test_a_clock_action_is_refused_while_another_is_in_flight(): void
    {
        $employee = $this->employee();

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => '2026-10-01',
            'timezone_snapshot' => 'UTC',
            'clock_in' => '2026-10-01 09:00:00',
            'break_taken' => 0,
        ]);

        $lock = Cache::lock('carevma:clock:'.$employee->id, 10);
        $this->assertTrue($lock->get(), 'Could not take the clock lock for the test.');

        try {
            $this->actingAs($employee->user)
                ->postJson('/api/employee/clock-out')
                ->assertStatus(409)
                ->assertJsonPath('code', 'CLOCK_ACTION_IN_PROGRESS');
        } finally {
            $lock->release();
        }

        $this->assertNull(
            $attendance->refresh()->clock_out,
            'The session was closed despite another clock action holding the lock.'
        );
    }

    /** With the lock free, the same action still works, so the lock cannot over-reach. */
    public function test_a_clock_action_succeeds_once_the_lock_is_free(): void
    {
        $employee = $this->employee();

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => '2026-10-01',
            'timezone_snapshot' => 'UTC',
            'clock_in' => '2026-10-01 09:00:00',
            'break_taken' => 0,
        ]);

        $this->actingAs($employee->user)
            ->postJson('/api/employee/clock-out')
            ->assertOk();

        $this->assertNotNull($attendance->refresh()->clock_out);
    }
}