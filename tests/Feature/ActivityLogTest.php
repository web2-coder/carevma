<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * OperationalActivityNotifier records every sign-in attempt, sign-out and successful
 * write to activity_logs; only admins can read the list.
 */
class ActivityLogTest extends TestCase
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

    public function test_sign_ins_and_writes_are_logged_and_listed_for_admin(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@carevma.test', 'password' => 'password', 'role' => 'admin']);

        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'wrong'])->assertStatus(422);
        $this->assertDatabaseHas('activity_logs', ['action' => 'Sign-in failed', 'actor_email' => $admin->email, 'actor_role' => 'guest', 'status_code' => 422]);

        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'Signed in', 'user_id' => $admin->id, 'module' => 'Account', 'status_code' => 200]);

        // An endpoint the notifier ignores is still logged.
        $this->actingAs($admin)->postJson('/api/birthday-popup/seen')->assertOk();
        $this->assertDatabaseHas('activity_logs', ['user_id' => $admin->id, 'path' => '/api/birthday-popup/seen', 'module' => 'Notifications', 'method' => 'POST']);

        $this->actingAs($admin)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'Signed out', 'user_id' => $admin->id]);

        $list = $this->actingAs($admin)->getJson('/api/admin/activity-logs?search=Signed&per_page=10')->assertOk();
        $actions = collect($list->json('data'))->pluck('action');
        $this->assertTrue($actions->contains('Signed in') && $actions->contains('Signed out'));
        $this->assertSame('Signed out', $actions->first(), 'Newest entry should come first.');
        $this->assertContains('Account', $list->json('modules'));
        $this->assertSame($admin->id, $list->json('actors.0.user_id'));

        $this->actingAs($admin)->getJson('/api/admin/activity-logs?module=Notifications')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reads_are_not_logged_and_non_admins_cannot_list(): void
    {
        $hr = User::create(['name' => 'HR', 'email' => 'hr@carevma.test', 'password' => 'password', 'role' => 'hr']);

        $this->actingAs($hr)->getJson('/api/me')->assertOk();
        $this->assertDatabaseCount('activity_logs', 0);

        $this->actingAs($hr)->getJson('/api/admin/activity-logs')->assertStatus(403);
    }
}
