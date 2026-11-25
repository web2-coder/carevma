<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use App\Services\InvoiceAttendanceScope;
use App\Services\InvoiceGenerator;
use App\Services\InvoiceSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceAssignmentScopeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('carevma_test', DB::connection()->getDatabaseName());
    }

    public function test_invalid_unlinked_attendance_is_excluded_from_generated_invoice_and_frozen_pdf(): void
    {
        $user = User::create(['name'=>'Scope VA', 'email'=>'scopetest-va@example.test', 'password'=>'password', 'role'=>'employee']);
        $va = Employee::create(['user_id'=>$user->id, 'first_name'=>'Scope', 'last_name'=>'VA', 'email'=>$user->email, 'password'=>'password', 'timezone'=>'UTC']);
        $doctorUser = User::create(['name'=>'Scope Doctor', 'email'=>'scopetest-doctor@example.test', 'password'=>'password', 'role'=>'client']);
        $client = Client::create(['user_id'=>$doctorUser->id, 'name'=>'Scope Doctor', 'email'=>$doctorUser->email, 'timezone'=>'UTC', 'per_hour_charges'=>10]);
        $scheduleId = DB::table('employee_client_schedules')->insertGetId([
            'client_id'=>$client->id,'employee_id'=>$va->id,'weekday'=>'Thursday',
            'start_time'=>'09:00:00','end_time'=>'17:00:00','no_of_hours'=>'8',
            'enabled'=>true,'repeat'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        // Invalid earlier C1 attendance must NOT become first assigned check-in.
        Attendance::create([
            'employee_id'=>$va->id,'client_id'=>$client->id,'date'=>'2026-09-30',
            'timezone_snapshot'=>'UTC','clock_in'=>'2026-09-30 09:00:00',
            'clock_out'=>'2026-09-30 17:00:00','break_taken'=>0,'total_minutes'=>480,
            'overtime'=>0,'overtime_status'=>'none',
        ]);
        Attendance::create([
            'employee_id'=>$va->id,'client_id'=>$client->id,'schedule_id'=>$scheduleId,'date'=>'2026-10-01',
            'timezone_snapshot'=>'UTC','clock_in'=>'2026-10-01 09:00:00',
            'clock_out'=>'2026-10-01 17:00:00','break_taken'=>0,'total_minutes'=>480,
            'overtime'=>0,'overtime_status'=>'none',
        ]);
        // Even inside a selected invoice period, a Friday without a matching schedule is excluded.
        Attendance::create([
            'employee_id'=>$va->id,'client_id'=>$client->id,'date'=>'2026-10-02',
            'timezone_snapshot'=>'UTC','clock_in'=>'2026-10-02 09:00:00',
            'clock_out'=>'2026-10-02 17:00:00','break_taken'=>0,'total_minutes'=>480,
            'overtime'=>0,'overtime_status'=>'none',
        ]);

        $scope = app(InvoiceAttendanceScope::class);
        $schedules = $scope->schedules($client, [$va->id])->get($va->id, collect());
        $this->assertSame('2026-10-01', $scope->firstValidCheckIn($client, $va->id, $schedules)->toDateString());

        $invoice = app(InvoiceGenerator::class)->generate($client,'2026-09-26','2026-10-25');
        $this->assertNotNull($invoice);
        $salary = $invoice->salaries()->firstOrFail();
        $this->assertSame('2026-10-01', (string) $salary->period_start);
        $this->assertSame(8.0, (float) $salary->total_hours);
        $this->assertSame(80.0, (float) $salary->salary_amount);

        $invoice = app(InvoiceSnapshotService::class)->freeze($invoice);
        $attendance = $invoice->salaries()->firstOrFail()->pdf_snapshot['attendance'];
        $this->assertCount(1, $attendance);
        $this->assertSame('2026-10-01 09:00:00', $attendance[0]['clock_in']);
    }
}
