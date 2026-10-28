<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeCodeTest extends TestCase
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
    }

    private function employee(string $email): Employee
    {
        $user = User::create([
            'name' => 'VA',
            'email' => $email,
            'password' => 'password',
            'role' => 'employee',
        ]);

        return Employee::create([
            'user_id' => $user->id,
            'first_name' => 'VA',
            'last_name' => 'One',
            'email' => $email,
            'password' => 'hashed',
        ]);
    }

    public function test_employee_code_is_derived_from_the_primary_key(): void
    {
        $first = $this->employee('one@carevma.test');
        $second = $this->employee('two@carevma.test');

        $this->assertSame('CVMA'.$first->id, $first->employee_code);
        $this->assertSame('CVMA'.$second->id, $second->employee_code);
        $this->assertNotSame($first->employee_code, $second->employee_code);

        // The value the caller holds is the persisted one, not the placeholder.
        $this->assertSame('CVMA'.$first->id, $first->fresh()->employee_code);
    }

    /**
     * The old scheme read the newest row's code and added one. A row whose code was
     * not of the form CVMA<n> made that arithmetic yield CVMA1, which the unique
     * index then rejected -- every later create failed with a 500.
     */
    public function test_an_unparseable_newest_code_does_not_break_the_next_create(): void
    {
        $first = $this->employee('one@carevma.test');
        $first->forceFill(['employee_code' => 'LEGACY-IMPORT'])->saveQuietly();

        $second = $this->employee('two@carevma.test');

        $this->assertSame('CVMA'.$second->id, $second->employee_code);
        $this->assertNotSame('CVMA1', $second->employee_code);
    }

    /**
     * employee_code is not mass-assignable, so this is the one way a caller can
     * supply one: direct assignment before save. The hook must leave it alone.
     */
    public function test_an_explicitly_assigned_employee_code_is_kept(): void
    {
        $user = User::create([
            'name' => 'VA',
            'email' => 'import@carevma.test',
            'password' => 'password',
            'role' => 'employee',
        ]);

        $employee = new Employee([
            'user_id' => $user->id,
            'first_name' => 'VA',
            'last_name' => 'Import',
            'email' => 'import@carevma.test',
            'password' => 'hashed',
        ]);
        $employee->employee_code = 'CVMA9001';
        $employee->save();

        $this->assertSame('CVMA9001', $employee->fresh()->employee_code);
    }
}
