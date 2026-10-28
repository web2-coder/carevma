<?php

namespace Tests\Feature;

use App\Models\CompanyFinanceEntry;
use App\Models\User;
use App\Services\TrustedTimeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TrustedClockFallbackAndFinanceTrendTest extends TestCase
{
    use RefreshDatabase;

    private const CLOCK_URL = 'https://clock.carevma.test/now';
    private const CACHE_KEY = 'carevma:trusted-time-offset:v3';

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
        config([
            'broadcasting.default' => 'null',
            'trusted-time.http_date_url' => self::CLOCK_URL,
            'trusted-time.manual_offset_seconds' => null,
            'trusted-time.failure_cache_seconds' => 60,
        ]);
        Cache::forget(self::CACHE_KEY);
    }

    public function test_a_failed_clock_lookup_is_not_retried_on_every_request(): void
    {
        // A response with no Date header is one of the two failure paths; unlike a
        // thrown connection error it is still recorded, so the sent count is checkable.
        Http::fake([self::CLOCK_URL => Http::response('', 503)]);

        // Two consumers, as two requests would be: the service is not a singleton.
        $this->assertSame('system-utc-fallback', app(TrustedTimeService::class)->source());
        $this->assertSame('system-utc-fallback', app(TrustedTimeService::class)->source());

        Http::assertSentCount(1);
        $this->assertSame('system-utc-fallback', Cache::get(self::CACHE_KEY)['source'] ?? null);
    }

    public function test_a_recovered_clock_is_used_once_the_failure_window_lapses(): void
    {
        Http::fake([self::CLOCK_URL => Http::response('', 204, [
            'Date' => gmdate('D, d M Y H:i:s').' GMT',
        ])]);

        // The failure window having expired is the same as the key being absent.
        Cache::forget(self::CACHE_KEY);

        $this->assertSame('http-date', app(TrustedTimeService::class)->source());
    }

    public function test_finance_day_view_trend_is_seven_days_with_real_history(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@carevma.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $anchor = Carbon::parse('2026-10-28', config('carevma.business_timezone', 'Asia/Karachi'));

        // Three days before the anchor: belongs in the week trend, not in the day summary.
        CompanyFinanceEntry::create([
            'entry_date' => $anchor->copy()->subDays(3)->toDateString(),
            'type' => 'expense',
            'category' => 'software',
            'amount' => 120,
            'created_by' => $admin->id,
        ]);
        // On the anchor day itself: belongs in both.
        CompanyFinanceEntry::create([
            'entry_date' => $anchor->toDateString(),
            'type' => 'income',
            'category' => 'other',
            'amount' => 50,
            'created_by' => $admin->id,
        ]);

        $data = $this->actingAs($admin)
            ->getJson('/api/admin/company-finance?view=day&date='.$anchor->toDateString())
            ->assertOk()
            ->json();

        $trend = $data['trend'];

        $this->assertCount(7, $trend, 'The week trend must hold exactly seven points.');
        $this->assertSame(
            collect(range(6, 0))->map(fn ($offset) => $anchor->copy()->subDays($offset)->format('D'))->all(),
            array_column($trend, 'label'),
            'The trend must run from six days ago through the anchor day, each day once.'
        );

        // Offset 3 from the end is the entry three days back.
        $this->assertSame(120.0, (float) $trend[3]['outgoing'], 'History before the anchor day came back empty.');
        $this->assertSame(50.0, (float) $trend[6]['income']);

        // The summary is still the anchor day alone.
        $this->assertSame(50.0, (float) $data['summary']['incoming']);
        $this->assertSame(0.0, (float) $data['summary']['outgoing']);
    }
}
