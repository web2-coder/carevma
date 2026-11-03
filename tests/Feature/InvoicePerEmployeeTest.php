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

class InvoicePerEmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");

        Mail::fake();
    }

    private function employee(string $first): Employee
    {
        $email = strtolower($first).'@carevma.test';
        $user = User::create(['name' => $first, 'email' => $email, 'password' => 'password', 'role' => 'employee']);

        return Employee::create([
            'user_id' => $user->id,
            'first_name' => $first,
            'last_name' => 'VA',
            'email' => $email,
            'password' => 'hashed',
            'timezone' => 'UTC',
        ]);
    }

    private function clientWithShift(Employee ...$employees): Client
    {
        $user = User::create(['name' => 'Doctor One', 'email' => 'doctor@carevma.test', 'password' => 'password', 'role' => 'client']);
        $client = Client::create(['user_id' => $user->id, 'name' => 'Doctor One', 'email' => 'doctor@carevma.test', 'timezone' => 'UTC', 'per_hour_charges' => 10]);

        foreach ($employees as $employee) {
            Attendance::create([
                'employee_id' => $employee->id,
                'client_id' => $client->id,
                'date' => '2026-10-01',
                'timezone_snapshot' => 'UTC',
                'clock_in' => '2026-10-01 09:00:00',
                'clock_out' => '2026-10-01 17:00:00',
                'break_taken' => 0,
                'total_minutes' => 480,
                'overtime' => 0,
                'overtime_status' => 'none',
            ]);
        }

        return $client;
    }

    public function test_an_invoice_can_be_limited_to_selected_vas_and_the_rest_stay_billable(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@carevma.test', 'password' => 'password', 'role' => 'admin']));
        $ada = $this->employee('Ada');
        $ben = $this->employee('Ben');
        $client = $this->clientWithShift($ada, $ben);
        $period = ['client_id' => $client->id, 'period_start' => '2026-09-26', 'period_end' => '2026-10-25'];

        $first = $this->postJson('/api/admin/invoices/generate', $period + ['employee_ids' => [$ada->id]])->assertStatus(201);
        $this->assertSame([$ada->id], array_column($first->json('invoice.salaries'), 'employee_id'));

        // Ada is already billed for these dates: nothing is left for her.
        $this->postJson('/api/admin/invoices/generate', $period + ['employee_ids' => [$ada->id]])->assertStatus(422);

        // "All assigned VAs" for the same exact period picks up only who is still outstanding.
        $second = $this->postJson('/api/admin/invoices/generate', $period)->assertStatus(201);
        $this->assertSame([$ben->id], array_column($second->json('invoice.salaries'), 'employee_id'));
        $this->assertNotSame($first->json('invoice.id'), $second->json('invoice.id'));

        // Everyone is billed now, so a third run creates nothing.
        $this->postJson('/api/admin/invoices/generate', $period)->assertStatus(422);
    }

    public function test_selected_vas_must_exist(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@carevma.test', 'password' => 'password', 'role' => 'admin']));
        $client = $this->clientWithShift();

        $this->postJson('/api/admin/invoices/generate', ['client_id' => $client->id, 'period_start' => '2026-09-26', 'period_end' => '2026-10-25', 'employee_ids' => [999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_ids.0']);
    }
}
