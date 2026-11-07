<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BillingCycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvoiceSettingsTest extends TestCase
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

    /** One client with one completed October shift, enough for a monthly draft. */
    private function clientWithOctoberShift(): Client
    {
        $vaUser = $this->user('employee', 'va@carevma.test');
        $employee = Employee::create(['user_id' => $vaUser->id, 'first_name' => 'VA', 'last_name' => 'One', 'email' => 'va@carevma.test', 'password' => 'hashed', 'timezone' => 'UTC']);
        $doctor = $this->user('client', 'doctor@carevma.test');
        $client = Client::create(['user_id' => $doctor->id, 'name' => 'Doctor One', 'email' => 'doctor@carevma.test', 'timezone' => 'UTC', 'per_hour_charges' => 10]);
        Attendance::create([
            'employee_id' => $employee->id, 'client_id' => $client->id, 'date' => '2026-10-01', 'timezone_snapshot' => 'UTC',
            'clock_in' => '2026-10-01 09:00:00', 'clock_out' => '2026-10-01 17:00:00', 'break_taken' => 0, 'total_minutes' => 480,
            'overtime' => 0, 'overtime_status' => 'none',
        ]);

        return $client;
    }

    private function pretendNowIs(string $utc): void
    {
        config(['trusted-time.manual_offset_seconds' => Carbon::parse($utc, 'UTC')->timestamp - time()]);
    }

    public function test_admin_can_change_the_cycle_and_hr_can_only_read_it(): void
    {
        $admin = $this->user('admin', 'admin@carevma.test');
        $hr = $this->user('hr', 'hr@carevma.test');

        $this->actingAs($admin)->getJson('/api/management/invoice-settings')
            ->assertOk()->assertJson(['cycle_start_day' => 26, 'auto_generate' => true]);

        $this->actingAs($admin)->putJson('/api/admin/invoice-settings', ['cycle_start_day' => 30, 'auto_generate' => true])
            ->assertOk()->assertJsonPath('settings.cycle_start_day', 30);

        $this->actingAs($admin)->putJson('/api/admin/invoice-settings', ['cycle_start_day' => 31, 'auto_generate' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['cycle_start_day']);

        $this->actingAs($admin)->putJson('/api/admin/invoice-settings', ['cycle_start_day' => 1, 'auto_generate' => false])
            ->assertOk()->assertJsonPath('settings.cycle_start_day', 1);

        $this->actingAs($hr)->getJson('/api/management/invoice-settings')
            ->assertOk()->assertJson(['cycle_start_day' => 1, 'auto_generate' => false]);
        $this->actingAs($hr)->putJson('/api/admin/invoice-settings', ['cycle_start_day' => 5, 'auto_generate' => true])
            ->assertStatus(403);

        $this->assertSame(1, BillingCycle::startDay());
    }

    public function test_cycle_math_follows_the_configured_start_day(): void
    {
        BillingCycle::save(['cycle_start_day' => 1]);
        $this->assertSame(['2026-10-01', '2026-10-31'], BillingCycle::latestCompleted(Carbon::parse('2026-11-03', 'UTC')));

        BillingCycle::save(['cycle_start_day' => 15]);
        $this->assertSame(['2026-09-15', '2026-10-14'], BillingCycle::latestCompleted(Carbon::parse('2026-11-03', 'UTC')));
        $this->assertSame(['2026-10-15', '2026-11-14'], BillingCycle::latestCompleted(Carbon::parse('2026-11-20', 'UTC')));
        $this->assertSame('2026-12-15 00:15:00', BillingCycle::dueAfter(Carbon::parse('2026-11-15', 'UTC'))->format('Y-m-d H:i:s'));

        BillingCycle::save(['cycle_start_day' => 30]);
        $this->assertSame(['2026-01-30', '2026-02-27'], BillingCycle::latestCompleted(Carbon::parse('2026-03-01', 'UTC')));
    }

    public function test_monthly_run_uses_the_configured_cycle_and_can_be_switched_off(): void
    {
        $this->clientWithOctoberShift();
        BillingCycle::save(['cycle_start_day' => 1, 'auto_generate' => true]);

        // Before the cycle closes: not due yet.
        $this->pretendNowIs('2026-10-31 23:00:00');
        $this->artisan('invoices:generate-monthly')->assertExitCode(0);
        $this->assertSame(0, Invoice::count());

        // After midnight on the next day (with no configured final-day shift): due.
        $this->pretendNowIs('2026-11-01 00:05:00');
        $this->artisan('invoices:generate-monthly')->assertExitCode(0);
        $invoice = Invoice::sole();
        $this->assertSame('2026-10-01', $invoice->period_start->toDateString());
        $this->assertSame('2026-10-31', $invoice->period_end->toDateString());

        // Switched off: a fresh client is left alone even on the due day.
        Invoice::query()->delete();
        BillingCycle::save(['auto_generate' => false]);
        $this->artisan('invoices:generate-monthly')->assertExitCode(0);
        $this->assertSame(0, Invoice::count());
    }
}
