<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvoiceLockAndDeferredNotificationsTest extends TestCase
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

    private function invoiceWithOneLine(string $status): Invoice
    {
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

        $vaUser = User::create([
            'name' => 'VA One',
            'email' => 'va@carevma.test',
            'password' => 'password',
            'role' => 'employee',
        ]);

        $employee = Employee::create([
            'user_id' => $vaUser->id,
            'first_name' => 'VA',
            'last_name' => 'One',
            'email' => 'va@carevma.test',
            'password' => 'hashed',
            'timezone' => 'UTC',
        ]);

        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-202610-TEST01',
            'period_start' => '2026-09-25',
            'period_end' => '2026-10-26',
            'total_hours' => 8,
            'total_amount' => 80,
            'status' => $status,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'total_hours' => 8,
            'salary_amount' => 80,
            'period_start' => '2026-09-25',
            'period_end' => '2026-10-26',
        ]);

        return $invoice;
    }

    private function editPayload(Invoice $invoice, array $overrides = []): array
    {
        return array_merge([
            'period_start' => '2026-09-25',
            'period_end' => '2026-10-26',
            'status' => $invoice->status,
            'lines' => $invoice->salaries->map(fn ($line) => [
                'id' => $line->id,
                'total_hours' => 99,
                'total_late' => 0,
                'total_overtime' => 0,
                'salary_amount' => 990,
            ])->all(),
        ], $overrides);
    }

    public function test_a_paid_invoice_rejects_an_amount_edit(): void
    {
        $admin = $this->admin();
        $invoice = $this->invoiceWithOneLine('paid')->load('salaries');

        $this->actingAs($admin)
            ->putJson("/api/admin/invoices/{$invoice->id}", $this->editPayload($invoice))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVOICE_LOCKED');

        $invoice->refresh()->load('salaries');

        $this->assertSame('80.00', (string) $invoice->total_amount);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('80.00', (string) $invoice->salaries->first()->salary_amount);
    }

    public function test_a_paid_invoice_can_still_be_voided(): void
    {
        $admin = $this->admin();
        $invoice = $this->invoiceWithOneLine('paid')->load('salaries');

        $this->actingAs($admin)
            ->putJson("/api/admin/invoices/{$invoice->id}", $this->editPayload($invoice, ['status' => 'void']))
            ->assertOk();

        $invoice->refresh()->load('salaries');

        $this->assertSame('void', $invoice->status);
        // The status moved, but the settled figures did not follow the payload.
        $this->assertSame('80.00', (string) $invoice->total_amount);
        $this->assertSame('80.00', (string) $invoice->salaries->first()->salary_amount);
    }

    public function test_a_draft_invoice_is_still_editable(): void
    {
        $admin = $this->admin();
        $invoice = $this->invoiceWithOneLine('draft')->load('salaries');

        $this->actingAs($admin)
            ->putJson("/api/admin/invoices/{$invoice->id}", $this->editPayload($invoice))
            ->assertOk();

        $invoice->refresh()->load('salaries');

        $this->assertSame('990.00', (string) $invoice->total_amount);
        $this->assertSame('990.00', (string) $invoice->salaries->first()->salary_amount);
    }

    /**
     * The fan-out moved into defer(), which runs on terminate. If that ever stops
     * firing, operational notifications and emails vanish silently -- so assert the
     * deferred work really happened.
     */
    public function test_deferred_operational_notifications_are_still_delivered(): void
    {
        $admin = $this->admin();

        $this->assertSame(0, Notification::count());

        $this->actingAs($admin)
            ->putJson('/api/management/account', [
                'name' => 'Admin Renamed',
                'email' => 'admin@carevma.test',
            ])
            ->assertOk();

        $this->assertGreaterThan(
            0,
            Notification::where('user_id', $admin->id)->count(),
            'The deferred notification fan-out did not run after the response was sent.'
        );

        Mail::assertSent(\App\Mail\OperationalActivityMail::class);
    }

    /**
     * The point of the change: none of the fan-out may happen while the client is
     * still waiting. Disabling the middleware that invokes deferred callbacks means
     * only work that ran inline during the request can have landed by assertion time.
     */
    public function test_notification_fan_out_does_not_run_inside_the_request(): void
    {
        $admin = $this->admin();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class);

        $this->actingAs($admin)
            ->putJson('/api/management/account', [
                'name' => 'Admin Renamed',
                'email' => 'admin@carevma.test',
            ])
            ->assertOk();

        $this->assertSame(
            0,
            Notification::count(),
            'Notifications were written inside the request instead of after the response.'
        );
        Mail::assertNothingSent();
    }
}
