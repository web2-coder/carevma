<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame(
            'carevma_test',
            $database,
            "Tests must run against carevma_test, not [{$database}]."
        );

        Mail::fake();
    }

    private function user(string $email, string $role = 'admin'): User
    {
        return User::create([
            'name' => 'Someone',
            'email' => $email,
            'password' => 'correct-password',
            'role' => $role,
        ]);
    }

    public function test_login_is_throttled_after_five_failures_for_one_email(): void
    {
        $this->user('admin@carevma.test');

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', ['email' => 'admin@carevma.test', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'admin@carevma.test', 'password' => 'wrong'])
            ->assertStatus(429);

        // Inside the window even the right password is refused: that is the point.
        $this->postJson('/api/login', ['email' => 'admin@carevma.test', 'password' => 'correct-password'])
            ->assertStatus(429);
    }

    public function test_login_throttle_does_not_lock_out_other_accounts_on_the_same_ip(): void
    {
        $this->user('hr@carevma.test', 'hr');

        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/login', ['email' => 'someone-else@carevma.test', 'password' => 'wrong']);
        }

        // A different email from the same IP has its own bucket.
        $this->postJson('/api/login', ['email' => 'hr@carevma.test', 'password' => 'correct-password'])
            ->assertOk();
    }

    public function test_forgot_password_is_throttled_per_ip(): void
    {
        // Different address each time: this bucket must be the IP, not the email,
        // or one source could relay reset mail to any number of addresses.
        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/forgot-password', ['email' => "probe{$attempt}@carevma.test"])
                ->assertStatus(422);
        }

        $this->postJson('/api/forgot-password', ['email' => 'probe7@carevma.test'])
            ->assertStatus(429);
    }

    public function test_reset_password_is_throttled_per_ip(): void
    {
        $payload = [
            'token' => 'guessed-token',
            'email' => 'admin@carevma.test',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ];

        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/reset-password', $payload)->assertStatus(422);
        }

        $this->postJson('/api/reset-password', $payload)->assertStatus(429);
    }
}
