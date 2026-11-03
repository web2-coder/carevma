<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(\App\Console\Kernel::class);

        // Keyed by email + IP rather than IP alone: a clinic office behind one NAT must
        // not lock every doctor out with a few shared typos, while credential stuffing
        // against one account from one source still stops at five attempts a minute.
        // Three tiers: a per-minute burst cap and an hourly cap on one account from one
        // source, plus a per-IP cap across accounts so one source cannot cycle emails.
        RateLimiter::for('login', function (Request $request) {
            $account = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return [
                Limit::perMinute(5)->by($account),
                Limit::perHour(20)->by('hourly|'.$account),
                Limit::perMinute(30)->by('ip|'.$request->ip()),
            ];
        });
    }
}
