<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeClientSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The topbar search answers from every module the role can open, and only
 * from records that role is allowed to see.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $hr;
    private User $aliceUser;
    private User $bobUser;
    private User $doctorUser;
    private Employee $alice;
    private Employee $bob;
    private Client $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");

        Mail::fake();
        config(['broadcasting.default' => 'null']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@carevma.test', 'password' => 'password', 'role' => 'admin']);
        $this->hr = User::create(['name' => 'Helen HR', 'email' => 'hr@carevma.test', 'password' => 'password', 'role' => 'hr']);
        $this->aliceUser = User::create(['name' => 'Alice VA', 'email' => 'alice@carevma.test', 'password' => 'password', 'role' => 'employee']);
        $this->bobUser = User::create(['name' => 'Bob VA', 'email' => 'bob@carevma.test', 'password' => 'password', 'role' => 'employee']);
        $this->doctorUser = User::create(['name' => 'Dr. Grey', 'email' => 'grey@carevma.test', 'password' => 'password', 'role' => 'client']);

        $this->alice = $this->employee($this->aliceUser, 'Alice');
        $this->bob = $this->employee($this->bobUser, 'Bob');
        $this->doctor = Client::create(['user_id' => $this->doctorUser->id, 'name' => 'Dr. Grey', 'business_name' => 'Grey Clinic', 'email' => 'grey@carevma.test', 'timezone' => 'UTC', 'per_hour_charges' => 10]);

        EmployeeClientSchedule::create([
            'employee_id' => $this->alice->id, 'client_id' => $this->doctor->id, 'weekday' => 'Monday',
            'start_time' => '09:00:00', 'end_time' => '17:00:00', 'timezone' => 'UTC', 'no_of_hours' => '8.00', 'enabled' => true, 'repeat' => true,
        ]);
    }

    private function employee(User $user, string $first): Employee
    {
        return Employee::create([
            'user_id' => $user->id, 'first_name' => $first, 'last_name' => 'VA', 'email' => $user->email, 'password' => 'hashed',
            'phone' => '+10000000000', 'date_of_birth' => '1990-05-04', 'gender' => 'female', 'marital_status' => 'single',
            'timezone' => 'UTC', 'profile_completed' => true,
        ]);
    }

    private function modules(array $json): array
    {
        return array_column($json['groups'], 'module');
    }

    private function urls(array $json, string $module): array
    {
        foreach ($json['groups'] as $group) {
            if ($group['module'] === $module) {
                return array_column($group['items'], 'url');
            }
        }
        return [];
    }

    public function test_admin_reaches_every_module_with_a_link(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/search?q=Alice')->assertOk()->json();
        $this->assertSame(["/admin/employees/{$this->alice->id}"], $this->urls($json, 'Employees'));
        $this->assertSame(['/admin/schedules?search=Alice'], $this->urls($json, 'Schedules'));
        $this->assertSame(["/admin/attendance?employee_id={$this->alice->id}"], $this->urls($json, 'Attendance'));

        $json = $this->actingAs($this->admin)->getJson('/api/search?q=Grey')->assertOk()->json();
        $this->assertSame(["/admin/client/{$this->doctor->id}/profile"], $this->urls($json, 'Clients'));

        $json = $this->actingAs($this->admin)->getJson('/api/search?q=Helen')->assertOk()->json();
        $this->assertSame(['/admin/hr-team?search=hr%40carevma.test'], $this->urls($json, 'HR Team'));
    }

    public function test_hr_links_land_in_the_hr_portal_and_skip_admin_only_modules(): void
    {
        $json = $this->actingAs($this->hr)->getJson('/api/search?q=Grey')->assertOk()->json();

        $this->assertSame(['Schedules'], $this->modules($json));
        $this->assertSame(['/hr/schedules?search=Grey'], $this->urls($json, 'Schedules'));
    }

    public function test_a_va_only_sees_their_own_records(): void
    {
        $json = $this->actingAs($this->bobUser)->getJson('/api/search?q=Alice')->assertOk()->json();
        $this->assertSame([], $json['groups']);

        $json = $this->actingAs($this->aliceUser)->getJson('/api/search?q=Grey')->assertOk()->json();
        $this->assertSame(['My Schedule'], $this->modules($json));
        $this->assertSame(['/employee/schedule?search=Grey'], $this->urls($json, 'My Schedule'));
    }

    public function test_a_doctor_only_sees_assigned_vas(): void
    {
        $json = $this->actingAs($this->doctorUser)->getJson('/api/search?q=Bob')->assertOk()->json();
        $this->assertSame([], $json['groups']);

        $json = $this->actingAs($this->doctorUser)->getJson('/api/search?q=Alice')->assertOk()->json();
        $this->assertSame(["/doctor/employee/{$this->alice->id}/attendance"], $this->urls($json, 'Assigned VAs'));
    }

    public function test_short_queries_return_nothing_and_wildcards_are_literal(): void
    {
        $this->actingAs($this->admin)->getJson('/api/search?q=A')->assertOk()->assertExactJson(['groups' => []]);
        $this->actingAs($this->admin)->getJson('/api/search?q=%25%25')->assertOk()->assertExactJson(['groups' => []]);
    }
}
