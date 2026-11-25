<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use App\Services\InvoiceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyInvoiceDoctorAssignmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('carevma_test', DB::connection()->getDatabaseName());
    }

    public function test_d1_gets_only_va1_va3_and_d2_only_va2_va5(): void
    {
        $employees = [];
        foreach ([1, 2, 3, 5] as $number) {
            $user = User::create([
                'name' => 'VA'.$number, 'email' => 'isolation-va'.$number.'@example.test',
                'password' => 'password', 'role' => 'employee',
            ]);
            $employees[$number] = Employee::create([
                'user_id' => $user->id, 'first_name' => 'VA'.$number,
                'last_name' => 'Invoice', 'email' => $user->email,
                'password' => 'password', 'timezone' => 'UTC',
            ]);
        }

        $doctors = [];
        foreach ([1, 2] as $number) {
            $user = User::create([
                'name' => 'Doctor '.$number,
                'email' => 'isolation-doctor'.$number.'@example.test',
                'password' => 'password', 'role' => 'client',
            ]);
            $doctors[$number] = Client::create([
                'user_id' => $user->id, 'name' => 'Doctor '.$number,
                'email' => $user->email, 'timezone' => 'UTC', 'per_hour_charges' => 10,
            ]);
        }

        foreach ([1 => [1, 3], 2 => [2, 5]] as $doctor => $assignedVas) {
            foreach ($assignedVas as $va) {
                $scheduleId = DB::table('employee_client_schedules')->insertGetId([
                    'client_id' => $doctors[$doctor]->id,
                    'employee_id' => $employees[$va]->id,
                    'weekday' => 'Thursday', 'start_time' => '09:00:00',
                    'end_time' => '17:00:00', 'no_of_hours' => '8',
                    'enabled' => true, 'repeat' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->attendance($doctors[$doctor], $employees[$va], $scheduleId);
            }
        }

        // Bad client-linked attendance without D1 assignment must be ignored.
        $this->attendance($doctors[1], $employees[2], null);
        $this->attendance($doctors[2], $employees[3], null);

        foreach ([1 => [1, 3], 2 => [2, 5]] as $doctor => $expected) {
            $invoice = app(InvoiceGenerator::class)->generate(
                $doctors[$doctor], '2026-09-26', '2026-10-25', true
            );
            $this->assertNotNull($invoice);
            $actual = $invoice->salaries->pluck('employee_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $expectedIds = collect($expected)->map(fn ($id) => $employees[$id]->id)->sort()->values()->all();
            $this->assertSame($expectedIds, $actual);
            $this->assertTrue((bool) $invoice->auto_generated);
        }
    }

    private function attendance(Client $client, Employee $employee, ?int $scheduleId): void
    {
        Attendance::create([
            'schedule_id' => $scheduleId, 'employee_id' => $employee->id,
            'client_id' => $client->id, 'date' => '2026-10-01',
            'timezone_snapshot' => 'UTC', 'clock_in' => '2026-10-01 09:00:00',
            'clock_out' => '2026-10-01 17:00:00', 'break_taken' => 0,
            'total_minutes' => 480, 'overtime' => 0, 'overtime_status' => 'none',
        ]);
    }
}
