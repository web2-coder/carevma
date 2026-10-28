<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The migrations are the schema. Request handlers used to probe
 * Schema::hasColumn() before touching columns that every migration in the repo
 * guarantees, which cost an information_schema round-trip per probe -- six on a
 * single clock-in -- and hid whether a deployment had actually been migrated.
 */
class NoRuntimeSchemaIntrospectionTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_a_clock_action_issues_no_schema_introspection_queries(): void
    {
        $user = User::create([
            'name' => 'VA One',
            'email' => 'va@carevma.test',
            'password' => 'password',
            'role' => 'employee',
        ]);

        $employee = Employee::create([
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
            'profile_completed' => true,
        ]);

        Attendance::create([
            'employee_id' => $employee->id,
            'date' => '2026-10-01',
            'timezone_snapshot' => 'UTC',
            'clock_in' => '2026-10-01 09:00:00',
            'break_taken' => 0,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->postJson('/api/employee/clock-out')->assertOk();

        $introspection = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => preg_match('/information_schema|show (full )?columns|show tables/i', $sql) === 1)
            ->values();

        $this->assertSame(
            [],
            $introspection->all(),
            "Schema introspection ran inside a request:\n".$introspection->implode("\n")
        );
    }
}
