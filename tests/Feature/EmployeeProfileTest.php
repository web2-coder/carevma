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

/**
 * The admin employee profile screen reads one endpoint: documents come back as
 * URLs and the current month's attendance is summed in the VA's own timezone.
 */
class EmployeeProfileTest extends TestCase
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

    public function test_employee_profile_returns_document_urls_and_a_month_summary(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@carevma.test', 'password' => 'password', 'role' => 'admin']);
        $vaUser = User::create(['name' => 'Alice', 'email' => 'alice@carevma.test', 'password' => 'password', 'role' => 'employee']);
        $employee = Employee::create([
            'user_id' => $vaUser->id, 'first_name' => 'Alice', 'last_name' => 'VA', 'email' => 'alice@carevma.test',
            'password' => 'hashed', 'timezone' => 'UTC', 'upload_documents' => ['employee_documents/cv.pdf'],
        ]);
        $doctor = User::create(['name' => 'Doctor', 'email' => 'doctor@carevma.test', 'password' => 'password', 'role' => 'client']);
        $client = Client::create(['user_id' => $doctor->id, 'name' => 'Doctor', 'email' => 'doctor@carevma.test', 'timezone' => 'UTC', 'per_hour_charges' => 10]);

        $today = now('UTC');
        $session = fn (string $date, int $minutes, int $late, int $overtime) => Attendance::create([
            'employee_id' => $employee->id, 'client_id' => $client->id, 'date' => $date, 'timezone_snapshot' => 'UTC',
            'clock_in' => "{$date} 09:00:00", 'clock_out' => "{$date} 17:00:00", 'break_taken' => 0,
            'total_minutes' => $minutes, 'late_minutes' => $late, 'overtime' => $overtime, 'overtime_status' => 'none',
        ]);
        $session($today->copy()->startOfMonth()->toDateString(), 480, 5, 30);
        $session($today->toDateString(), 120, 0, 0);
        // Last month must not leak into "this month".
        $session($today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), 999, 99, 99);

        $json = $this->actingAs($admin)
            ->getJson("/api/management/employees/{$employee->id}")
            ->assertOk()
            ->json();

        $this->assertSame(['sessions' => 2, 'minutes' => 600, 'late_minutes' => 5, 'overtime_minutes' => 30, 'late_sessions' => 1, 'pending_overtime_minutes' => 0], $json['month_summary']);
        $this->assertFalse($json['is_live']);
        $this->assertSame(['/storage/employee_documents/cv.pdf'], $json['upload_documents']);
        $this->assertSame('Alice', $json['user']['name']);
    }
}
