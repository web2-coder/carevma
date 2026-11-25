<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\ClientEmployeeRate;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceGenerator;
use App\Services\InvoicePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceIntegrityImprovementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Invoice Integrity Admin',
            'email' => 'invoice-integrity-admin@carevma.test',
            'password' => 'password',
            'role' => 'admin',
        ]);
    }

    private function client(string $name = 'Doctor Integrity'): Client
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'@carevma.test',
            'password' => 'password',
            'role' => 'client',
        ]);

        return Client::create([
            'user_id' => $user->id,
            'name' => $name,
            'email' => $user->email,
            'business_name' => 'Integrity Clinic',
            'timezone' => 'UTC',
            'per_hour_charges' => 10,
        ]);
    }

    private function employee(string $firstName = 'Snapshot', string $lastName = 'VA'): Employee
    {
        $user = User::create([
            'name' => $firstName.' '.$lastName,
            'email' => strtolower($firstName.'-'.$lastName).'@carevma.test',
            'password' => 'password',
            'role' => 'employee',
        ]);

        return Employee::create([
            'user_id' => $user->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $user->email,
            'password' => 'password',
            'timezone' => 'UTC',
        ]);
    }

    private function attendance(Employee $employee, Client $client, array $overrides = []): Attendance
    {
        // An invoice test must have a real Doctor/VA assignment; random linked
        // attendance is intentionally no longer accepted as billing evidence.
        $scheduleId = DB::table('employee_client_schedules')->insertGetId([
            'client_id' => $client->id, 'employee_id' => $employee->id,
            'weekday' => 'Thursday', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
            'no_of_hours' => '9', 'enabled' => true, 'repeat' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Attendance::create(array_merge([
            'schedule_id' => $scheduleId,
            'employee_id' => $employee->id,
            'client_id' => $client->id,
            'date' => '2026-10-01',
            'timezone_snapshot' => 'UTC',
            'shift_start_snapshot' => '2026-10-01 09:00:00',
            'shift_end_snapshot' => '2026-10-01 18:00:00',
            'clock_in' => '2026-10-01 09:00:00',
            'clock_out' => '2026-10-01 19:00:00',
            'break_limit' => '01:00:00',
            'break_taken' => 3600,
            'total_minutes' => 480,
            'overtime' => 60,
            'overtime_status' => 'pending',
            'approved_overtime_minutes' => 0,
            'late_minutes' => 0,
        ], $overrides));
    }

    public function test_overtime_review_recalculates_existing_unsent_draft_invoice(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $employee = $this->employee('Draft', 'VA');
        ClientEmployeeRate::create(['client_id' => $client->id, 'employee_id' => $employee->id, 'per_hour_rate' => 10]);
        $attendance = $this->attendance($employee, $client);

        $invoice = app(InvoiceGenerator::class)->generate($client, '2026-09-26', '2026-10-25', false);
        $this->assertNotNull($invoice);
        $salary = $invoice->salaries()->firstOrFail();
        $this->assertSame(8.0, (float) $salary->total_hours);
        $this->assertSame(80.0, (float) $salary->salary_amount);

        $this->actingAs($admin)
            ->postJson('/api/management/overtime/'.$attendance->id.'/review', [
                'decision' => 'approved',
                'approved_minutes' => 30,
            ])
            ->assertOk();

        $salary->refresh();
        $invoice->refresh();
        $this->assertSame(8.5, (float) $salary->total_hours);
        $this->assertSame(30, (int) $salary->total_overtime);
        $this->assertSame(85.0, (float) $salary->salary_amount);
        $this->assertSame(8.5, (float) $invoice->total_hours);
        $this->assertSame(85.0, (float) $invoice->total_amount);
    }

    public function test_issued_invoice_uses_frozen_pdf_snapshot_and_rejects_financial_rewrite(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $employee = $this->employee();
        $rate = ClientEmployeeRate::create(['client_id' => $client->id, 'employee_id' => $employee->id, 'per_hour_rate' => 10]);
        $attendance = $this->attendance($employee, $client, [
            'clock_out' => '2026-10-01 18:00:00',
            'overtime' => 0,
            'overtime_status' => 'none',
        ]);

        $invoice = app(InvoiceGenerator::class)->generate($client, '2026-09-26', '2026-10-25', false);
        $salary = $invoice->salaries()->firstOrFail();

        $payload = [
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'status' => 'issued',
            'lines' => [[
                'id' => $salary->id,
                'total_hours' => (float) $salary->total_hours,
                'total_late' => (int) $salary->total_late,
                'total_overtime' => (int) $salary->total_overtime,
                'salary_amount' => (float) $salary->salary_amount,
            ]],
            'adjustments' => [],
        ];

        $this->actingAs($admin)
            ->putJson('/api/admin/invoices/'.$invoice->id, $payload)
            ->assertOk()
            ->assertJsonPath('invoice.status', 'issued');

        $invoice->refresh();
        $salary->refresh();
        $this->assertNotNull($invoice->snapshot_at);
        $this->assertIsArray($invoice->pdf_snapshot);
        $this->assertIsArray($salary->pdf_snapshot);

        $employee->update(['first_name' => 'Changed']);
        $client->update(['name' => 'Changed Doctor']);
        $rate->update(['per_hour_rate' => 99]);
        $attendance->update([
            'total_minutes' => 60,
            'overtime' => 60,
            'overtime_status' => 'approved',
            'approved_overtime_minutes' => 60,
        ]);

        $html = preg_replace('/>\s+</', '><', app(InvoicePdfService::class)->make($invoice)->getDomPDF()->outputHtml());
        $this->assertStringContainsString('Snapshot VA', $html);
        $this->assertStringNotContainsString('Changed VA', $html);
        $this->assertStringContainsString('Doctor Integrity', $html);
        $this->assertStringNotContainsString('Changed Doctor', $html);
        $this->assertStringContainsString('$10.00', $html);
        $this->assertStringContainsString('(Payable Hours: 8h)', $html);

        $rewrite = $payload;
        $rewrite['lines'][0]['salary_amount'] = 999;
        $this->actingAs($admin)
            ->putJson('/api/admin/invoices/'.$invoice->id, $rewrite)
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVOICE_LOCKED');

        $this->assertSame(80.0, (float) $invoice->fresh()->total_amount);
    }

    public function test_invoice_monitoring_counts_first_checkin_clamped_invoice_by_billing_month(): void
    {
        $admin = $this->admin();
        $client = $this->client('Doctor Monitoring');

        Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'MONITOR-FIRST-CHECKIN',
            'period_start' => '2026-09-28',
            'period_end' => '2026-10-25',
            'billing_cycle_month' => '2026-10-01',
            'total_hours' => 1,
            'total_amount' => 10,
            'status' => 'draft',
            'auto_generated' => true,
            'generated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/invoices-overview?month=2026-10')
            ->assertOk()
            ->assertJson([
                'total_doctors' => 1,
                'auto_generated_doctors' => 1,
                'pending_doctors' => 0,
            ]);
    }

    public function test_overtime_api_includes_work_with_doctor_details(): void
    {
        $admin = $this->admin();
        $client = $this->client('Doctor Work With');
        $employee = $this->employee('Work', 'With');
        $this->attendance($employee, $client);

        $this->actingAs($admin)
            ->getJson('/api/management/overtime')
            ->assertOk()
            ->assertJsonPath('data.0.client.name', 'Doctor Work With')
            ->assertJsonPath('data.0.client.business_name', 'Integrity Clinic');
    }
}
