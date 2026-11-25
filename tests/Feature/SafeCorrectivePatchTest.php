<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\EmployeeClientSchedule;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SafeCorrectivePatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");
        Mail::fake();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'safe-patch-admin@carevma.test',
            'password' => 'password',
            'role' => 'admin',
        ]);
    }

    private function client(): Client
    {
        $user = User::create([
            'name' => 'Doctor Safe',
            'email' => 'safe-doctor@carevma.test',
            'password' => 'password',
            'role' => 'client',
        ]);

        return Client::create([
            'user_id' => $user->id,
            'name' => 'Doctor Safe',
            'email' => 'safe-doctor@carevma.test',
            'timezone' => 'UTC',
        ]);
    }

    private function invoice(Client $client, string $number, string $status, bool $sent = false): Invoice
    {
        return Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => $number,
            'period_start' => '2026-09-26',
            'period_end' => '2026-10-25',
            'billing_cycle_month' => '2026-10-01',
            'total_hours' => 1,
            'total_amount' => 10,
            'status' => $status,
            'auto_generated' => false,
            'generated_at' => now(),
            'sent_at' => $sent ? now() : null,
        ]);
    }

    public function test_bulk_delete_removes_only_unsent_drafts_and_keeps_finalized_invoices(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $draft = $this->invoice($client, 'SAFE-DRAFT', 'draft');
        $issued = $this->invoice($client, 'SAFE-ISSUED', 'issued');
        $sentDraft = $this->invoice($client, 'SAFE-SENT-DRAFT', 'draft', true);

        $response = $this->actingAs($admin)->postJson('/api/admin/invoices/bulk-delete', [
            'invoice_ids' => [$draft->id, $issued->id, $sentDraft->id],
        ])->assertOk();

        $response->assertJson([
            'deleted_count' => 1,
            'protected_count' => 2,
        ]);

        $this->assertDatabaseMissing('invoices', ['id' => $draft->id]);
        $this->assertDatabaseHas('invoices', ['id' => $issued->id]);
        $this->assertDatabaseHas('invoices', ['id' => $sentDraft->id]);
    }

    public function test_single_delete_rejects_finalized_invoice(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $paid = $this->invoice($client, 'SAFE-PAID', 'paid');

        $this->actingAs($admin)
            ->deleteJson('/api/admin/invoices/'.$paid->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('invoices', ['id' => $paid->id]);
    }

    public function test_employee_onboarding_documents_are_private_and_downloaded_through_authenticated_route(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/api/management/employees', [
            'first_name' => 'Private',
            'last_name' => 'Document',
            'email' => 'private-document@carevma.test',
            'timezone' => 'UTC',
            'upload_documents' => [UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf')],
        ])->assertCreated();

        $employee = Employee::findOrFail($response->json('employee.id'));
        $reference = Employee::documentPaths($employee->upload_documents)[0] ?? '';
        $this->assertStringStartsWith('private:employees/documents/', $reference);

        $privatePath = substr($reference, strlen('private:'));
        Storage::disk('local')->assertExists($privatePath);
        Storage::disk('public')->assertMissing($privatePath);

        $profile = $this->actingAs($admin)
            ->getJson('/api/management/employees/'.$employee->id)
            ->assertOk();

        $downloadUrl = $profile->json('upload_documents.0');
        $this->assertStringStartsWith('/api/management/employees/'.$employee->id.'/documents/0', $downloadUrl);
        $this->get($downloadUrl)->assertOk();
    }


    public function test_historical_attendance_snapshot_remains_billable_after_live_schedule_changes(): void
    {
        $employeeUser = User::create([
            'name' => 'Historical VA',
            'email' => 'historical-va@carevma.test',
            'password' => 'password',
            'role' => 'employee',
        ]);
        $employee = Employee::create([
            'user_id' => $employeeUser->id,
            'first_name' => 'Historical',
            'last_name' => 'VA',
            'email' => 'historical-va@carevma.test',
            'password' => 'password',
            'timezone' => 'UTC',
        ]);
        $client = $this->client();

        // The live schedule now says Monday, while this attendance was genuinely
        // worked on Thursday and carries the original shift snapshots.
        $schedule = EmployeeClientSchedule::create([
            'employee_id' => $employee->id,
            'client_id' => $client->id,
            'weekday' => 'Monday',
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'no_of_hours' => '8',
            'timezone' => 'UTC',
            'enabled' => true,
            'repeat' => true,
        ]);

        Attendance::create([
            'employee_id' => $employee->id,
            'client_id' => $client->id,
            'schedule_id' => $schedule->id,
            'date' => '2026-10-01',
            'timezone_snapshot' => 'UTC',
            'shift_start_snapshot' => '2026-10-01 09:00:00',
            'shift_end_snapshot' => '2026-10-01 17:00:00',
            'clock_in' => '2026-10-01 09:00:00',
            'clock_out' => '2026-10-01 17:00:00',
            'break_taken' => 0,
            'total_minutes' => 480,
            'overtime' => 0,
            'overtime_status' => 'none',
        ]);

        $invoice = app(\App\Services\InvoiceGenerator::class)
            ->generate($client, '2026-09-26', '2026-10-25', false);

        $this->assertNotNull($invoice);
        $this->assertSame([$employee->id], $invoice->salaries->pluck('employee_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_probation_management_route_is_registered_and_updates_employee(): void
    {
        $admin = $this->admin();
        $user = User::create([
            'name' => 'Probation VA',
            'email' => 'probation-va@carevma.test',
            'password' => 'password',
            'role' => 'employee',
        ]);
        $employee = Employee::create([
            'user_id' => $user->id,
            'first_name' => 'Probation',
            'last_name' => 'VA',
            'email' => 'probation-va@carevma.test',
            'password' => 'password',
            'timezone' => 'UTC',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/management/employees/'.$employee->id.'/probation', [
                'action' => 'extend',
                'end_date' => now()->addMonth()->toDateString(),
            ])
            ->assertOk();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'probation_end_date' => now()->addMonth()->toDateString(),
        ]);
    }
}
