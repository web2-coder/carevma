<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceChangeRequest;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OvertimeApprovalAndInvoiceGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RefreshDatabase truncates whatever it is pointed at. Refuse to run against
        // anything but the dedicated test database.
        $database = DB::connection()->getDatabaseName();
        $this->assertSame(
            'carevma_test',
            $database,
            "Tests must run against carevma_test, not [{$database}]."
        );

        Mail::fake();
        // Operational notifications fan out on every write; keep them off the wire.
        config(['broadcasting.default' => 'null']);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@carevma.test',
            'password' => 'password',
            'role' => 'admin',
        ]);
    }

    private function employee(string $email = 'va@carevma.test'): Employee
    {
        $user = User::create([
            'name' => 'VA One',
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
            'timezone' => 'UTC',
        ]);
    }

    /** A 09:00-17:00 shift clocked out at 18:00 is 60 minutes of overtime. */
    private function attendanceWithApprovedOvertime(Employee $employee, User $approver): Attendance
    {
        return Attendance::create([
            'employee_id' => $employee->id,
            'date' => '2026-10-01',
            'timezone_snapshot' => 'UTC',
            'shift_start_snapshot' => '2026-10-01 09:00:00',
            'shift_end_snapshot' => '2026-10-01 17:00:00',
            'clock_in' => '2026-10-01 09:00:00',
            'clock_out' => '2026-10-01 18:00:00',
            'break_taken' => 0,
            'total_minutes' => 480,
            'overtime' => 60,
            'overtime_status' => 'approved',
            'approved_overtime_minutes' => 60,
            'overtime_amount' => 120.00,
            'overtime_approved_by' => $approver->id,
            'overtime_approved_at' => Carbon::parse('2026-10-02 10:00:00', 'UTC'),
        ]);
    }

    public function test_unchanged_overtime_keeps_the_existing_approval(): void
    {
        $admin = $this->admin();
        $employee = $this->employee();
        $attendance = $this->attendanceWithApprovedOvertime($employee, $admin);

        // Same clock-out, so the recalculated overtime is identical: 60 minutes.
        $change = AttendanceChangeRequest::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'date' => '2026-10-01',
            'requested_clock_in' => '09:00:00',
            'requested_clock_out' => '18:00:00',
            'reason' => 'Corrected note only.',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/management/change-requests/{$change->id}/review", ['decision' => 'approved'])
            ->assertOk();

        $attendance->refresh();

        $this->assertSame(60, (int) $attendance->overtime);
        $this->assertSame('approved', $attendance->overtime_status);
        $this->assertSame(60, (int) $attendance->approved_overtime_minutes);
        $this->assertSame('120.00', (string) $attendance->overtime_amount);
        $this->assertSame($admin->id, (int) $attendance->overtime_approved_by);
        $this->assertNotNull($attendance->overtime_approved_at);
    }

    public function test_changed_overtime_sends_the_decision_back_for_review(): void
    {
        $admin = $this->admin();
        $employee = $this->employee();
        $attendance = $this->attendanceWithApprovedOvertime($employee, $admin);

        // Clock-out moves to 19:00, so overtime becomes 120 and the old price is stale.
        $change = AttendanceChangeRequest::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'date' => '2026-10-01',
            'requested_clock_in' => '09:00:00',
            'requested_clock_out' => '19:00:00',
            'reason' => 'Stayed an extra hour.',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/management/change-requests/{$change->id}/review", ['decision' => 'approved'])
            ->assertOk();

        $attendance->refresh();

        $this->assertSame(120, (int) $attendance->overtime);
        $this->assertSame('pending', $attendance->overtime_status);
        $this->assertSame(0, (int) $attendance->approved_overtime_minutes);
        $this->assertNull($attendance->overtime_amount);
        $this->assertNull($attendance->overtime_approved_by);
    }

    public function test_monthly_invoice_run_is_still_due_later_on_the_twenty_seventh(): void
    {
        $this->admin();
        $employee = $this->employee();

        $clientUser = User::create([
            'name' => 'Doctor One',
            'email' => 'doctor@carevma.test',
            'password' => 'password',
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Doctor One',
            'email' => 'doctor@carevma.test',
            'timezone' => 'UTC',
            'per_hour_charges' => 10,
        ]);

        Attendance::create([
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

        // 05:00 on the client-local 27th: long past 00:15, so the old exact-minute
        // gate would have skipped this client and billed nothing all month.
        config([
            'trusted-time.manual_offset_seconds' =>
                Carbon::parse('2026-10-27 05:00:00', 'UTC')->timestamp - time(),
        ]);

        $this->artisan('invoices:generate-monthly')->assertExitCode(0);

        $invoice = Invoice::first();

        $this->assertNotNull($invoice, 'The 27th billing close did not run after 00:15.');
        $this->assertSame('2026-09-25', $invoice->period_start->toDateString());
        $this->assertSame('2026-10-26', $invoice->period_end->toDateString());
    }
}
