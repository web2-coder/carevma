<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PersonalThemeNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must use carevma_test, not [{$database}].");
        Mail::fake();
        config(['broadcasting.default' => 'null']);
    }

    private function user(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_employee_and_doctor_theme_changes_do_not_notify_admin_or_hr(): void
    {
        $admin = $this->user('admin', 'admin@theme.test');
        $hr = $this->user('hr', 'hr@theme.test');
        $employee = $this->user('employee', 'employee@theme.test');
        $doctor = $this->user('client', 'doctor@theme.test');

        $this->actingAs($employee)->putJson('/api/profile/theme-mode', ['mode' => 'dark'])
            ->assertOk()->assertJsonPath('mode', 'dark');
        $this->actingAs($doctor)->putJson('/api/profile/theme-mode', ['mode' => 'light'])
            ->assertOk()->assertJsonPath('mode', 'light');

        $this->assertSame(0, Notification::whereIn('user_id', [$admin->id, $hr->id])->count());
        $this->assertSame(0, Notification::count());
    }

    public function test_regular_profile_update_still_notifies_operations_users(): void
    {
        $admin = $this->user('admin', 'admin2@theme.test');
        $employee = $this->user('employee', 'employee2@theme.test');

        $this->actingAs($employee)->putJson('/api/profile', [
            'name' => 'Updated Employee',
            'phone' => '',
            'designation' => '',
            'locale' => 'en',
            'timezone' => 'UTC',
            'notifications' => true,
        ])->assertOk();

        $this->assertGreaterThan(0, Notification::where('user_id', $admin->id)->count());
    }
}
