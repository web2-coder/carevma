<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");

        Mail::fake();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@carevma.test', 'password' => 'password', 'role' => 'admin']);
        $this->hr = User::create(['name' => 'Helen', 'email' => 'hr@carevma.test', 'password' => 'password', 'role' => 'hr']);
    }

    public function test_hr_starts_with_its_classic_modules_and_nothing_else(): void
    {
        $me = $this->actingAs($this->hr)->getJson('/api/me')->assertOk()->json('user.permissions');
        $this->assertEqualsCanonicalizing(['live-users', 'employees', 'schedules', 'attendance', 'change-requests', 'overtime', 'notebook', 'configuration'], $me);

        $this->actingAs($this->hr)->getJson('/api/management/employees')->assertOk();
        $this->actingAs($this->hr)->getJson('/api/admin/clients')->assertStatus(403)->assertJsonPath('message', 'You do not have access to the Clients module.');
        $this->actingAs($this->hr)->getJson('/api/admin/invoices')->assertStatus(403);
        $this->actingAs($this->hr)->getJson('/api/admin/roles')->assertStatus(403);
    }

    public function test_admin_can_grant_and_revoke_modules_for_a_role(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/roles')->assertOk()
            ->assertJsonPath('roles.0.key', 'hr')
            ->assertJsonFragment(['key' => 'clients', 'label' => 'Clients', 'group' => 'People']);

        $this->actingAs($this->admin)->putJson('/api/admin/roles/hr', ['modules' => ['employees', 'clients', 'bogus']])
            ->assertStatus(422)->assertJsonValidationErrors(['modules.2']);
        $this->actingAs($this->admin)->putJson('/api/admin/roles/employee', ['modules' => ['clients']])->assertStatus(404);

        $this->actingAs($this->admin)->putJson('/api/admin/roles/hr', ['modules' => ['employees', 'clients']])
            ->assertOk()->assertJsonPath('grants.hr', ['employees', 'clients']);

        // Granted: the clients API opens, and the VA's own session carries the new grant.
        $this->actingAs($this->hr)->getJson('/api/admin/clients')->assertOk();
        $this->assertEqualsCanonicalizing(['employees', 'clients'], $this->actingAs($this->hr)->getJson('/api/me')->json('user.permissions'));

        // Revoked: schedules are gone, even though HR had them by default.
        $this->actingAs($this->hr)->getJson('/api/management/schedules')->assertStatus(403);

        // Admins are never limited by the matrix.
        $this->actingAs($this->admin)->getJson('/api/management/schedules')->assertOk();
        $this->assertCount(14, $this->actingAs($this->admin)->getJson('/api/me')->json('user.permissions'));
    }
}
