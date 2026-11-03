<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");

        Mail::fake();
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => 'password', 'role' => $role]);
    }

    public function test_notification_links_name_the_page_and_the_row(): void
    {
        $admin = $this->user('admin', 'admin@carevma.test');
        $hr = $this->user('hr', 'hr@carevma.test');
        $vaUser = $this->user('employee', 'va@carevma.test');
        $employee = Employee::create(['user_id' => $vaUser->id, 'first_name' => 'VA', 'last_name' => 'One', 'email' => 'va@carevma.test', 'password' => 'hashed', 'timezone' => 'UTC']);
        $doctor = $this->user('client', 'doctor@carevma.test');
        $client = Client::create(['user_id' => $doctor->id, 'name' => 'Doctor One', 'email' => 'doctor@carevma.test', 'timezone' => 'UTC', 'per_hour_charges' => 10]);

        $attendance = Attendance::create([
            'employee_id' => $employee->id, 'client_id' => $client->id, 'date' => '2026-11-03', 'timezone_snapshot' => 'UTC',
            'clock_in' => '2026-11-03 00:29:00', 'clock_out' => '2026-11-03 08:19:00', 'break_taken' => 0, 'total_minutes' => 421,
            'overtime' => 19, 'overtime_status' => 'pending', 'approved_overtime_minutes' => 0,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/management/overtime/{$attendance->id}/review", ['decision' => 'approved', 'approved_minutes' => 19])
            ->assertOk();

        $linkFor = fn (User $user) => Notification::where('user_id', $user->id)->latest('id')->value('action_url');

        // The VA lands on their attendance page with the reviewed row marked; HR on the overtime queue, same row.
        $this->assertSame("/employee/attendance?highlight=attendance:{$attendance->id}", $linkFor($vaUser));
        $this->assertSame("/hr/overtime?highlight=attendance:{$attendance->id}", $linkFor($hr));
        // The actor gets nothing about their own action.
        $this->assertNull(Notification::where('user_id', $admin->id)->latest('id')->value('action_url'));
    }
}
