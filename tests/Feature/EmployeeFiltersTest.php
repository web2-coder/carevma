<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");
    }

    private function employee(string $first, array $attributes): Employee
    {
        $email = strtolower($first).'@carevma.test';
        $user = User::create(['name' => $first, 'email' => $email, 'password' => 'password', 'role' => 'employee']);

        return Employee::create($attributes + ['user_id' => $user->id, 'first_name' => $first, 'last_name' => 'VA', 'email' => $email, 'password' => 'hashed', 'timezone' => 'UTC']);
    }

    public function test_employees_can_be_filtered_by_department_designation_status_and_timezone(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@carevma.test', 'password' => 'password', 'role' => 'admin']);
        $seo = Department::create(['name' => 'SEO']);
        $ops = Department::create(['name' => 'Operations']);
        $lead = Designation::create(['name' => 'Team Lead']);
        $active = EmployeeStatus::create(['name' => 'Active']);
        $left = EmployeeStatus::create(['name' => 'Left']);

        $ada = $this->employee('Ada', ['department_id' => $seo->id, 'designation_id' => $lead->id, 'employee_status_id' => $active->id, 'timezone' => 'Asia/Karachi']);
        $ben = $this->employee('Ben', ['department_id' => $ops->id, 'designation_id' => null, 'employee_status_id' => $left->id, 'timezone' => 'America/New_York']);

        $names = fn (array $params) => collect($this->actingAs($admin)->getJson('/api/management/employees?'.http_build_query($params))->assertOk()->json('data'))->pluck('first_name')->all();

        $this->assertEqualsCanonicalizing(['Ada', 'Ben'], $names([]));
        $this->assertSame(['Ada'], $names(['department_id' => $seo->id]));
        $this->assertSame(['Ada'], $names(['designation_id' => $lead->id]));
        $this->assertSame(['Ben'], $names(['employee_status_id' => $left->id]));
        $this->assertSame(['Ben'], $names(['timezone' => 'America/New_York']));
        $this->assertSame([], $names(['department_id' => $seo->id, 'timezone' => 'America/New_York']));
        $this->assertSame(['Ada'], $names(['department_id' => $seo->id, 'search' => 'Ada']));
        $this->assertNotNull($ben->id);
    }
}
