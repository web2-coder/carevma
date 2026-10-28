<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The list endpoints share Controller::sortAndPaginate(): ?sort against a per-endpoint
 * whitelist, ?direction, ?per_page capped at 100, id as the tie-breaker.
 */
class ListSortingAndPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");

        Mail::fake();
        config(['broadcasting.default' => 'null']);
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@carevma.test', 'password' => 'password', 'role' => 'admin']);
    }

    private function employee(string $first, string $email): Employee
    {
        $user = User::create(['name' => $first, 'email' => $email, 'password' => 'password', 'role' => 'employee']);

        return Employee::create([
            'user_id' => $user->id, 'first_name' => $first, 'last_name' => 'VA',
            'email' => $email, 'password' => 'hashed', 'timezone' => 'UTC',
        ]);
    }

    private function client(): Client
    {
        $user = User::create(['name' => 'Doctor', 'email' => 'doctor@carevma.test', 'password' => 'password', 'role' => 'client']);

        return Client::create(['user_id' => $user->id, 'name' => 'Doctor', 'email' => 'doctor@carevma.test', 'timezone' => 'UTC', 'per_hour_charges' => 10]);
    }

    public function test_employees_sort_by_a_whitelisted_column_and_honour_per_page(): void
    {
        $admin = $this->admin();
        $this->employee('Carol', 'carol@carevma.test');
        $this->employee('Alice', 'alice@carevma.test');
        $this->employee('Bob', 'bob@carevma.test');

        $page1 = $this->actingAs($admin)
            ->getJson('/api/management/employees?sort=first_name&direction=asc&per_page=2')
            ->assertOk()
            ->json();

        $this->assertSame(['Alice', 'Bob'], array_column($page1['data'], 'first_name'));
        $this->assertSame(2, $page1['per_page']);
        $this->assertSame(3, $page1['total']);
        $this->assertSame(2, $page1['last_page']);

        $page2 = $this->actingAs($admin)
            ->getJson('/api/management/employees?sort=first_name&direction=asc&per_page=2&page=2')
            ->assertOk()
            ->json();

        $this->assertSame(['Carol'], array_column($page2['data'], 'first_name'));

        $desc = $this->actingAs($admin)
            ->getJson('/api/management/employees?sort=first_name&direction=desc&per_page=10')
            ->assertOk()
            ->json('data');

        $this->assertSame(['Carol', 'Bob', 'Alice'], array_column($desc, 'first_name'));
    }

    public function test_an_unknown_sort_key_falls_back_to_the_default_order(): void
    {
        $admin = $this->admin();
        $first = $this->employee('Alice', 'alice@carevma.test');
        $second = $this->employee('Bob', 'bob@carevma.test');

        // Not in the whitelist: must neither error nor order by it. Default is id desc.
        $rows = $this->actingAs($admin)
            ->getJson('/api/management/employees?sort=password&direction=asc')
            ->assertOk()
            ->json('data');

        $this->assertSame([$second->id, $first->id], array_column($rows, 'id'));
    }

    public function test_invoices_page_and_sort_by_amount(): void
    {
        $admin = $this->admin();
        $client = $this->client();

        foreach (range(1, 26) as $i) {
            Invoice::create([
                'client_id' => $client->id, 'invoice_number' => sprintf('INV-TEST-%02d', $i),
                'period_start' => '2026-09-25', 'period_end' => '2026-10-26',
                'total_hours' => $i, 'total_amount' => $i * 10, 'status' => 'draft',
            ]);
        }

        $page1 = $this->actingAs($admin)->getJson('/api/admin/invoices?per_page=25')->assertOk()->json();
        $this->assertCount(25, $page1['data']);
        $this->assertSame(2, $page1['last_page']);

        $page2 = $this->actingAs($admin)->getJson('/api/admin/invoices?per_page=25&page=2')->assertOk()->json('data');
        $this->assertCount(1, $page2);

        $top = $this->actingAs($admin)->getJson('/api/admin/invoices?sort=total_amount&direction=desc&per_page=1')->assertOk()->json('data.0');
        $this->assertSame('INV-TEST-26', $top['invoice_number']);
    }

    public function test_attendance_sorts_by_regular_minutes_for_admin_and_doctor(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $employee = $this->employee('Alice', 'alice@carevma.test');
        DB::table('employee_client_schedules')->insert([
            'client_id' => $client->id, 'employee_id' => $employee->id, 'weekday' => 'monday',
            'start_time' => '09:00:00', 'end_time' => '17:00:00', 'no_of_hours' => 8, 'timezone' => 'UTC',
            'enabled' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([480, 120, 300] as $i => $minutes) {
            Attendance::create([
                'employee_id' => $employee->id, 'client_id' => $client->id, 'date' => '2026-10-0'.($i + 1),
                'timezone_snapshot' => 'UTC', 'clock_in' => '2026-10-0'.($i + 1).' 09:00:00',
                'clock_out' => '2026-10-0'.($i + 1).' 17:00:00', 'break_taken' => 0,
                'total_minutes' => $minutes, 'overtime' => 0, 'overtime_status' => 'none',
            ]);
        }

        $adminRows = $this->actingAs($admin)
            ->getJson('/api/management/attendance?sort=total_minutes&direction=asc')
            ->assertOk()
            ->json('data');
        $this->assertSame([120, 300, 480], array_map('intval', array_column($adminRows, 'total_minutes')));

        $doctorRows = $this->actingAs($client->user)
            ->getJson("/api/doctor/employees/{$employee->id}/attendance?sort=total_minutes&direction=desc")
            ->assertOk()
            ->json('attendance.data');
        $this->assertSame([480, 300, 120], array_map('intval', array_column($doctorRows, 'total_minutes')));
    }

    public function test_hr_users_and_clients_sort_by_name(): void
    {
        $admin = $this->admin();
        // Created in ascending name order on purpose: the default ordering is id desc,
        // so without a working sort these come back reversed and the test is red.
        foreach (['Amy', 'Zed'] as $name) {
            User::create(['name' => $name, 'email' => strtolower($name).'@carevma.test', 'password' => 'password', 'role' => 'hr']);
        }
        foreach (['Alpine Practice', 'Westside Clinic'] as $name) {
            $user = User::create(['name' => $name, 'email' => str_replace(' ', '', strtolower($name)).'@carevma.test', 'password' => 'password', 'role' => 'client']);
            Client::create(['user_id' => $user->id, 'name' => $name, 'email' => $user->email, 'timezone' => 'UTC']);
        }

        $hr = $this->actingAs($admin)->getJson('/api/admin/hr-users?sort=name&direction=asc&per_page=1')->assertOk()->json();
        $this->assertSame(['Amy'], array_column($hr['data'], 'name'));
        $this->assertSame(1, $hr['per_page']);
        $this->assertSame(2, $hr['total']);

        $clients = $this->actingAs($admin)->getJson('/api/admin/clients?sort=name&direction=asc')->assertOk()->json('data');
        $this->assertSame(['Alpine Practice', 'Westside Clinic'], array_column($clients, 'name'));
    }

    public function test_change_requests_and_overtime_sort_and_page(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $employee = $this->employee('Alice', 'alice@carevma.test');

        $attendances = [];
        foreach ([['2026-10-03', 90], ['2026-10-01', 30], ['2026-10-02', 60]] as [$date, $overtime]) {
            $attendances[] = Attendance::create([
                'employee_id' => $employee->id, 'client_id' => $client->id, 'date' => $date,
                'timezone_snapshot' => 'UTC', 'clock_in' => "{$date} 09:00:00", 'clock_out' => "{$date} 18:00:00",
                'break_taken' => 0, 'total_minutes' => 480, 'overtime' => $overtime, 'overtime_status' => 'pending',
            ]);
        }
        foreach ($attendances as $attendance) {
            DB::table('attendance_change_requests')->insert([
                'attendance_id' => $attendance->id, 'employee_id' => $employee->id, 'date' => $attendance->date->toDateString(),
                'requested_clock_in' => '09:00:00', 'requested_clock_out' => '17:00:00', 'reason' => 'Test',
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $requests = $this->actingAs($admin)->getJson('/api/management/change-requests?sort=date&direction=asc')->assertOk()->json('data');
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], array_map(fn ($r) => substr($r['date'], 0, 10), $requests));

        // Overtime used to paginate at a fixed 25 and ignore per_page entirely.
        $overtime = $this->actingAs($admin)->getJson('/api/management/overtime?sort=overtime&direction=desc&per_page=1')->assertOk()->json();
        $this->assertSame(1, $overtime['per_page']);
        $this->assertSame(3, $overtime['total']);
        $this->assertSame(90, (int) $overtime['data'][0]['overtime']);
    }

    public function test_schedules_sort_and_page_while_keeping_their_calendar_default(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $employee = $this->employee('Alice', 'alice@carevma.test');

        foreach ([['Monday', '13:00'], ['Monday', '09:00'], ['Tuesday', '08:00'], ['Monday', '11:00']] as [$weekday, $start]) {
            DB::table('employee_client_schedules')->insert([
                'client_id' => $client->id, 'employee_id' => $employee->id, 'weekday' => $weekday,
                'start_time' => "{$start}:00", 'end_time' => '17:00:00', 'no_of_hours' => 4, 'timezone' => 'UTC',
                'enabled' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $starts = fn (array $rows) => array_map(fn ($r) => substr($r['start_time'], 0, 5), $rows);

        // Descending plus a page size the old branch clamped up to 10: red on both counts before.
        $page = $this->actingAs($admin)
            ->getJson('/api/management/schedules?paginate=1&sort=start_time&direction=desc&per_page=2')
            ->assertOk()->json();
        $this->assertSame(['13:00', '11:00'], $starts($page['data']));
        $this->assertSame(2, $page['per_page']);
        $this->assertSame(4, $page['total']);

        // No sort: calendar weekday order, then start time -- the composite default survives.
        $default = $this->actingAs($admin)->getJson('/api/management/schedules?paginate=1')->assertOk()->json('data');
        $this->assertSame(['Monday', 'Monday', 'Monday', 'Tuesday'], array_column($default, 'weekday'));
        $this->assertSame(['09:00', '11:00', '13:00', '08:00'], $starts($default));

        // The non-paginated response other screens rely on keeps the same ordering.
        $plain = $this->actingAs($admin)->getJson("/api/management/schedules?employee_id={$employee->id}")->assertOk()->json();
        $this->assertSame(['09:00', '11:00', '13:00', '08:00'], $starts($plain));
    }
}
