<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use App\Services\InvoiceGenerator;
use App\Services\InvoicePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoicePdfHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");
    }

    public function test_pdf_shows_worked_hours_and_bills_only_approved_overtime(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@carevma.test', 'password' => 'password', 'role' => 'employee']);
        $employee = Employee::create(['user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'VA', 'email' => 'ada@carevma.test', 'password' => 'hashed', 'timezone' => 'UTC']);
        $doctor = User::create(['name' => 'Doctor One', 'email' => 'doctor@carevma.test', 'password' => 'password', 'role' => 'client']);
        $client = Client::create(['user_id' => $doctor->id, 'name' => 'Doctor One', 'email' => 'doctor@carevma.test', 'timezone' => 'UTC', 'per_hour_charges' => 10]);

        $base = ['employee_id' => $employee->id, 'client_id' => $client->id, 'timezone_snapshot' => 'UTC', 'break_taken' => 1800, 'overtime_amount' => null];
        // 00:29-08:19 against a 00:00-08:00 shift: 7h 01m inside the shift, 19m approved overtime.
        Attendance::create($base + ['date' => '2026-11-03', 'clock_in' => '2026-11-03 00:29:00', 'clock_out' => '2026-11-03 08:19:00', 'total_minutes' => 421, 'overtime' => 19, 'overtime_status' => 'approved', 'approved_overtime_minutes' => 19]);
        // 10m of overtime still awaiting review: shown, but not counted or billed.
        Attendance::create($base + ['date' => '2026-11-04', 'clock_in' => '2026-11-04 12:31:00', 'clock_out' => '2026-11-04 14:24:00', 'total_minutes' => 83, 'overtime' => 10, 'overtime_status' => 'pending', 'approved_overtime_minutes' => 0]);

        $invoice = app(InvoiceGenerator::class)->generate($client, '2026-10-26', '2026-11-25');

        $this->assertNotNull($invoice);
        // (421 + 19 + 83) minutes at $10/h: 87.17, with the pending 10m left out.
        $this->assertSame(8.72, (float) $invoice->total_hours);
        $this->assertSame(87.17, (float) $invoice->total_amount);

        $html = preg_replace('/>\s+</', '><', app(InvoicePdfService::class)->make($invoice)->getDomPDF()->outputHtml());

        $this->assertStringContainsString('<td>7h 01m</td><td>19m</td><td>7h 20m</td>', $html);
        $this->assertStringContainsString('<td>1h 23m</td><td>0m (10m pending)</td><td>1h 23m</td>', $html);
        $this->assertStringContainsString('(Working Hours: 8h 43m)', $html);
    }
}
