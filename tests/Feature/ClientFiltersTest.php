<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");
    }

    private function client(string $name, string $business, string $country, string $timezone): Client
    {
        $email = strtolower(str_replace(' ', '.', $name)).'@carevma.test';
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => 'client',
        ]);

        return Client::create([
            'user_id' => $user->id,
            'name' => $name,
            'email' => $email,
            'business_name' => $business,
            'country' => $country,
            'timezone' => $timezone,
        ]);
    }

    public function test_clients_can_be_filtered_by_business_country_and_timezone(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@carevma.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $this->client('Doctor One', 'North Clinic', 'United States', 'America/New_York');
        $this->client('Doctor Two', 'Gulf Health', 'United Arab Emirates', 'Asia/Dubai');
        $this->client('Doctor Three', 'North Clinic', 'Canada', 'America/Toronto');

        $names = function (array $params) use ($admin): array {
            return collect(
                $this->actingAs($admin)
                    ->getJson('/api/admin/clients?'.http_build_query($params))
                    ->assertOk()
                    ->json('data')
            )->pluck('name')->sort()->values()->all();
        };

        $this->assertSame(['Doctor One', 'Doctor Three'], $names(['business_name' => 'North Clinic']));
        $this->assertSame(['Doctor Two'], $names(['country' => 'United Arab Emirates']));
        $this->assertSame(['Doctor One'], $names(['timezone' => 'America/New_York']));
        $this->assertSame(['Doctor Three'], $names([
            'business_name' => 'North Clinic',
            'country' => 'Canada',
            'timezone' => 'America/Toronto',
        ]));
    }
}
