<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvoicePerEmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");

        Mail::fake();
    }

    private function employee(string $first): Employee
    {
        $email = strtolower($first).'@carevma.test';
        $user = User::create(['name' => $first, 'email' => $email, 'password' => 'password', 'role' => 'employee']);

        return Employee::create([
            'user_id' => $user->id,
            'first_name' => $first,
            'last_name' => 'VA',
            'email' => $email,
            'password' => 'hashed',
            'timezone' => 'UTC',
        ]);
    }

    private function clientWithShift(Employee ...$employees): Client
    {
        $user = User::create(['name' => 'Doctor One', 'email' => 'doctor@carevma.test', 'password' => 'password', 'role' => 'client']);
        $client = Client::create(['user_id' => $user->id, 'name' => 'Doctor One', 'email' => 'doctor@carevma.test', 'timezone' => 'UTC', 'per_hour_charges' => 10]);

        foreach ($employees as $employee) {
            // Explicit Doctor assignment: arbitrary attendance alone is not billable.
            $scheduleId = DB::table('employee_client_schedules')->insertGetId([
                'client_id' => $client->id,
                'employee_id' => $employee->id,
                'weekday' => 'Thursday',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'no_of_hours' => '8',
                'enabled' => true,
                'repeat' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Attendance::create([
                'schedule_id' => $scheduleId,
                'employee_id' => $employee->id,
                'client_id' => $client->id,
                'date' => '2026-10-01',
                'timezone_snapshot' => 'UTC',
                'clock_in' => '2026-10-01 09:00:00',
                'clock_out' => '2026-10-01 17:00:00',
                'break_taken' => 0,
                'total_minutes' => 480,
                'overtime' => 0,
                'overtime_status' => 'none',
            ]);
        }

        return $client;
    }

    public function test_an_invoice_can_be_limited_to_selected_vas_and_the_rest_stay_billable(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@carevma.test', 'password' => 'password', 'role' => 'admin']));
        $ada = $this->employee('Ada');
        $ben = $this->employee('Ben');
        $client = $this->clientWithShift($ada, $ben);
        $period = ['client_id' => $client->id, 'period_start' => '2026-09-26', 'period_end' => '2026-10-25'];

        $first = $this->postJson('/api/admin/invoices/generate', $period + ['employee_ids' => [$ada->id]])->assertStatus(201);
        $this->assertSame([$ada->id], array_column($first->json('invoice.salaries'), 'employee_id'));

        // Ada is already billed for these dates: nothing is left for her.
        $this->postJson('/api/admin/invoices/generate', $period + ['employee_ids' => [$ada->id]])->assertStatus(422);

        // "All assigned VAs" for the same exact period picks up only who is still
        // outstanding and appends that VA to the same editable Doctor invoice.
        $second = $this->postJson('/api/admin/invoices/generate', $period)->assertStatus(201);
        $this->assertSame($first->json('invoice.id'), $second->json('invoice.id'));
        $this->assertSame(
            [$ada->id, $ben->id],
            collect($second->json('invoice.salaries'))->pluck('employee_id')->map(fn ($id) => (int) $id)->sort()->values()->all()
        );

        // Everyone is billed now, so a third run creates nothing.
        $this->postJson('/api/admin/invoices/generate', $period)->assertStatus(422);
    }


    public function test_one_generation_run_creates_one_combined_doctor_invoice_for_all_vas(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin2@carevma.test', 'password' => 'password', 'role' => 'admin']));
        $ada = $this->employee('AdaTwo');
        $ben = $this->employee('BenTwo');
        $client = $this->clientWithShift($ada, $ben);
        $period = ['client_id' => $client->id, 'period_start' => '2026-09-26', 'period_end' => '2026-10-25'];

        $response = $this->postJson('/api/admin/invoices/generate', $period)->assertStatus(201);

        $invoices = collect($response->json('invoices'));
        $this->assertCount(1, $invoices);
        $this->assertSame($response->json('invoice.id'), $invoices->first()['id']);
        $this->assertSame(
            [$ada->id, $ben->id],
            collect($invoices->first()['salaries'])->pluck('employee_id')->map(fn ($id) => (int) $id)->sort()->values()->all()
        );
        $this->assertCount(2, $invoices->first()['salaries']);
    }

    public function test_auto_generation_skips_only_the_va_assignment_already_manually_invoiced(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin3@carevma.test', 'password' => 'password', 'role' => 'admin']));
        $ada = $this->employee('AdaAuto');
        $ben = $this->employee('BenAuto');
        $client = $this->clientWithShift($ada, $ben);
        $periodStart = '2026-09-26';
        $periodEnd = '2026-10-25';

        $manual = app(\App\Services\InvoiceGenerator::class)
            ->generateMany($client, $periodStart, $periodEnd, false, [$ada->id]);
        $this->assertCount(1, $manual);
        $this->assertSame($ada->id, (int) $manual->first()->salaries->first()->employee_id);
        $this->assertFalse((bool) $manual->first()->auto_generated);

        $automatic = app(\App\Services\InvoiceGenerator::class)
            ->generateMany($client, $periodStart, $periodEnd, true);

        $this->assertCount(1, $automatic);
        $this->assertSame($ben->id, (int) $automatic->first()->salaries->first()->employee_id);
        $this->assertTrue((bool) $automatic->first()->auto_generated);
        $this->assertNotSame($manual->first()->id, $automatic->first()->id);
        $this->assertSame([$ada->id], $manual->first()->fresh('salaries')->salaries->pluck('employee_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$ben->id], $automatic->first()->fresh('salaries')->salaries->pluck('employee_id')->map(fn ($id) => (int) $id)->all());

        $this->assertSame(1, \App\Models\EmployeeSalary::query()
            ->where('client_id', $client->id)
            ->where('employee_id', $ada->id)
            ->count());
        $this->assertSame(1, \App\Models\EmployeeSalary::query()
            ->where('client_id', $client->id)
            ->where('employee_id', $ben->id)
            ->count());
    }

    public function test_unassigned_v4_is_excluded_while_v1_v2_v3_are_included(): void
    {
        $v1 = $this->employee('ScopeV1');
        $v2 = $this->employee('ScopeV2');
        $v3 = $this->employee('ScopeV3');
        $v4 = $this->employee('ScopeV4');
        $client = $this->clientWithShift($v1, $v2, $v3);

        // V4 has an erroneous attendance row pointing at C1, but NO assignment.
        Attendance::create([
            'employee_id' => $v4->id,
            'client_id' => $client->id,
            'date' => '2026-10-01',
            'timezone_snapshot' => 'UTC',
            'clock_in' => '2026-10-01 09:00:00',
            'clock_out' => '2026-10-01 17:00:00',
            'break_taken' => 0,
            'total_minutes' => 480,
            'overtime' => 0,
            'overtime_status' => 'none',
        ]);

        $invoice = app(\App\Services\InvoiceGenerator::class)
            ->generate($client, '2026-09-26', '2026-10-25');
        $this->assertNotNull($invoice);
        $this->assertSame(
            [$v1->id, $v2->id, $v3->id],
            $invoice->salaries->pluck('employee_id')->map(fn ($id) => (int) $id)->sort()->values()->all()
        );
    }

    public function test_disabled_historical_schedule_remains_billable_when_attendance_links_it(): void
    {
        $va = $this->employee('HistoricVA');
        $client = $this->clientWithShift($va);
        $scheduleId = DB::table('employee_client_schedules')
            ->where('client_id', $client->id)->where('employee_id', $va->id)->value('id');
        DB::table('employee_client_schedules')->where('id', $scheduleId)->update(['enabled' => false]);

        $invoice = app(\App\Services\InvoiceGenerator::class)
            ->generate($client, '2026-09-26', '2026-10-25');
        $this->assertNotNull($invoice);
        $this->assertSame([$va->id], $invoice->salaries->pluck('employee_id')->all());
    }

    public function test_selected_vas_must_exist(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@carevma.test', 'password' => 'password', 'role' => 'admin']));
        $client = $this->clientWithShift();

        $this->postJson('/api/admin/invoices/generate', ['client_id' => $client->id, 'period_start' => '2026-09-26', 'period_end' => '2026-10-25', 'employee_ids' => [999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_ids.0']);
    }
}
