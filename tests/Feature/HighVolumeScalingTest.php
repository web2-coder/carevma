<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HighVolumeScalingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");
        config(['broadcasting.default' => 'null']);
    }

    private function admin(): User
    {
        return User::create(['name' => 'Scale Admin', 'email' => 'scale-admin@carevma.test', 'password' => 'password', 'role' => 'admin']);
    }

    private function employee(int $i): Employee
    {
        $user = User::create([
            'name' => "Scale Employee {$i}",
            'email' => "scale.employee.{$i}@carevma.test",
            'password' => 'password',
            'role' => 'employee',
            'last_seen_at' => now()->subHour(),
        ]);
        return Employee::create([
            'user_id' => $user->id,
            'first_name' => 'Scale',
            'last_name' => sprintf('Employee %03d', $i),
            'email' => $user->email,
            'password' => 'password',
            'timezone' => 'UTC',
        ]);
    }

    private function client(int $i): Client
    {
        $user = User::create([
            'name' => "Scale Doctor {$i}",
            'email' => "scale.doctor.{$i}@carevma.test",
            'password' => 'password',
            'role' => 'client',
        ]);
        return Client::create([
            'user_id' => $user->id,
            'name' => sprintf('Scale Doctor %03d', $i),
            'email' => $user->email,
            'business_name' => 'Scale Clinic '.sprintf('%03d', $i),
            'country' => 'United States',
            'timezone' => 'UTC',
        ]);
    }

    public function test_management_options_stays_lightweight_and_people_lookup_is_bounded(): void
    {
        $admin = $this->admin();
        foreach (range(1, 35) as $i) $this->employee($i);
        foreach (range(1, 35) as $i) $this->client($i);

        $options = $this->actingAs($admin)->getJson('/api/management/options')->assertOk()->json();
        $this->assertSame([], $options['employees']);
        $this->assertSame([], $options['clients']);

        $employees = $this->actingAs($admin)
            ->getJson('/api/management/people-options?type=employee&q=Scale&limit=10')
            ->assertOk()->json('data');
        $this->assertCount(10, $employees);

        $clients = $this->actingAs($admin)
            ->getJson('/api/management/people-options?type=client&q=Scale&limit=12')
            ->assertOk()->json('data');
        $this->assertCount(12, $clients);

        $users = $this->actingAs($admin)
            ->getJson('/api/management/people-options?type=user&q=Scale&limit=15')
            ->assertOk()->json('data');
        $this->assertCount(15, $users);
        $this->assertTrue(collect($users)->every(fn ($row) => isset($row['value'], $row['label'], $row['meta'])));
    }

    public function test_live_workforce_offline_tab_is_server_paginated(): void
    {
        $admin = $this->admin();
        foreach (range(1, 30) as $i) $this->employee($i);

        $page = $this->actingAs($admin)
            ->getJson('/api/management/live-users?status=offline&per_page=10&page=2')
            ->assertOk()->json();

        $this->assertCount(10, $page['sessions']);
        $this->assertSame(2, $page['current_page']);
        $this->assertSame(3, $page['last_page']);
        $this->assertSame(30, $page['total_filtered']);
        $this->assertSame(30, $page['offline']);
    }

    public function test_invoice_run_details_loads_one_doctor_page_instead_of_all_doctors(): void
    {
        $admin = $this->admin();
        foreach (range(1, 31) as $i) $this->client($i);

        $page = $this->actingAs($admin)
            ->getJson('/api/management/invoice-run-details?period_start=2026-10-26&period_end=2026-11-25&per_page=10&page=2')
            ->assertOk()->json();

        $this->assertCount(10, $page['data']);
        $this->assertSame(2, $page['current_page']);
        $this->assertSame(4, $page['last_page']);
        $this->assertSame(31, $page['total']);
    }
}
