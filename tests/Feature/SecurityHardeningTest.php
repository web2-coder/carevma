<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");
    }

    public function test_inactive_admin_cannot_download_an_invoice_pdf_with_an_existing_session(): void
    {
        $admin = User::create([
            'name' => 'Inactive Admin',
            'email' => 'inactive-admin@carevma.test',
            'password' => 'password',
            'role' => 'admin',
            'is_active' => false,
        ]);
        $doctor = User::create([
            'name' => 'Doctor',
            'email' => 'doctor-security@carevma.test',
            'password' => 'password',
            'role' => 'client',
        ]);
        $client = Client::create([
            'user_id' => $doctor->id,
            'name' => 'Doctor Security',
            'email' => 'doctor-security@carevma.test',
            'timezone' => 'America/New_York',
        ]);
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'SEC-1001',
            'period_start' => '2026-10-26',
            'period_end' => '2026-11-25',
            'total_hours' => 0,
            'total_amount' => 0,
        ]);

        $this->actingAs($admin)
            ->get('/invoices/'.$invoice->id.'/pdf')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_INACTIVE');

        $this->assertGuest();
    }

    public function test_inactive_employee_cannot_connect_or_disconnect_google_with_an_existing_session(): void
    {
        $employee = User::create([
            'name' => 'Inactive Employee',
            'email' => 'inactive-employee@carevma.test',
            'password' => 'password',
            'role' => 'employee',
            'is_active' => false,
        ]);

        $this->actingAs($employee)
            ->get('/google/connect')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_INACTIVE');

        $this->assertGuest();

        $this->actingAs($employee)
            ->post('/google/disconnect')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_INACTIVE');

        $this->assertGuest();
    }
}
