<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OvertimeDashboardCountConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('carevma_test', DB::connection()->getDatabaseName(),
            'Only run this test against the dedicated carevma_test database.');
    }

    public function test_dashboard_pending_count_matches_reviewable_overtime_rows(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'overtime-count-admin@carevma.test',
            'password' => 'password', 'role' => 'admin',
        ]);

        $employeeUser = User::create([
            'name' => 'Overtime Fixture VA',
            'email' => 'overtime-count-va@carevma.test',
            'password' => 'password',
            'role' => 'employee',
        ]);
        $employee = Employee::create([
            'user_id' => $employeeUser->id,
            'first_name' => 'Overtime',
            'last_name' => 'Fixture',
            'email' => 'overtime-count-va@carevma.test',
            'password' => 'hashed',
            'timezone' => 'UTC',
        ]);

        foreach ([
            ['pending', 0],
            ['review_required', 0],
            ['pending', 45],
            ['review_required', 30],
            ['approved', 60],
            ['none', 0],
        ] as $index => [$status, $minutes]) {
            Attendance::create([
                'employee_id' => $employee->id,
                'date' => Carbon::parse('2026-11-01')->addDays($index)->toDateString(),
                'clock_in' => '2026-11-01 09:00:00',
                'clock_out' => '2026-11-01 17:00:00',
                'overtime' => $minutes,
                'overtime_status' => $status,
                'total_minutes' => 480,
                'break_taken' => 0,
            ]);
        }

        $dashboard = $this->actingAs($admin)->getJson('/api/management/dashboard')->assertOk()->json();
        $list = $this->actingAs($admin)->getJson('/api/management/overtime?per_page=100')->assertOk()->json();

        $this->assertSame(2, (int) data_get($dashboard, 'metrics.pending_overtime'));
        $this->assertSame(3, (int) data_get($list, 'total'));
        $this->assertCount(2, collect($list['data'])->filter(
            fn ($row) => in_array($row['overtime_status'], ['pending', 'review_required'], true)
        ));
    }
}
