<?php

namespace App\Services;

use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * CareVMA trusted clock.
 *
 * The host/browser timezone is never used as the source of truth. CareVMA first
 * resolves a UTC epoch offset from an HTTP Date header, caches that offset for a
 * short period, then applies assigned IANA timezones for presentation and shift
 * calculations. This also protects local Windows/XAMPP environments whose wall
 * clock may be configured incorrectly.
 */
class TrustedTimeService
{
    private ?int $resolvedOffsetSeconds = null;
    private string $resolvedSource = 'system-utc';

    public function now(string $timezone = 'UTC'): Carbon
    {
        return $this->utcNow()->setTimezone($this->validTimezone($timezone, 'UTC'));
    }

    public function utcNow(): Carbon
    {
        $epoch = time() + $this->offsetSeconds();
        return Carbon::createFromTimestampUTC($epoch);
    }

    public function source(): string
    {
        $this->offsetSeconds();
        return $this->resolvedSource;
    }

    private function offsetSeconds(): int
    {
        if ($this->resolvedOffsetSeconds !== null) {
            return $this->resolvedOffsetSeconds;
        }

        $manual = config('trusted-time.manual_offset_seconds');
        if ($manual !== null && $manual !== '' && is_numeric($manual)) {
            $this->resolvedOffsetSeconds = (int) $manual;
            $this->resolvedSource = 'configured-offset';
            return $this->resolvedOffsetSeconds;
        }

        $cacheKey = 'carevma:trusted-time-offset:v3';
        $cached = null;
        try {
            $cached = Cache::get($cacheKey);
        } catch (\Throwable) {
            // Cache storage may not be available yet during a fresh migration.
        }
        if (is_array($cached) && isset($cached['offset'])) {
            $this->resolvedOffsetSeconds = (int) $cached['offset'];
            $this->resolvedSource = (string) ($cached['source'] ?? 'http-date');
            return $this->resolvedOffsetSeconds;
        }

        $url = trim((string) config('trusted-time.http_date_url', ''));
        if ($url !== '') {
            try {
                $startedAt = microtime(true);
                $response = Http::connectTimeout((float) config('trusted-time.connect_timeout', 1.0))
                    ->timeout((float) config('trusted-time.timeout', 2.0))
                    ->withHeaders([
                        'Accept' => '*/*',
                        'Cache-Control' => 'no-cache',
                        'User-Agent' => 'CareVMA-TrustedClock/1.0',
                    ])
                    ->get($url);

                $dateHeader = $response->header('Date');
                if ($dateHeader) {
                    $remoteEpoch = Carbon::parse($dateHeader, 'UTC')->timestamp;
                    $halfRoundTrip = (int) round(max(0, microtime(true) - $startedAt) / 2);
                    $offset = ($remoteEpoch + $halfRoundTrip) - time();

                    // Reject obviously invalid responses while allowing real clock drift.
                    if (abs($offset) <= 172800) {
                        $payload = ['offset' => $offset, 'source' => 'http-date'];
                        try {
                            Cache::put($cacheKey, $payload, max(30, (int) config('trusted-time.cache_seconds', 300)));
                        } catch (\Throwable) {
                            // Continue with the in-memory offset even when persistent cache is unavailable.
                        }
                        $this->resolvedOffsetSeconds = $offset;
                        $this->resolvedSource = 'http-date';
                        return $offset;
                    }
                }
            } catch (\Throwable) {
                // Fall through to system UTC. Business actions must remain available
                // even when the external clock endpoint is temporarily unreachable.
            }
        }

        $this->resolvedOffsetSeconds = 0;
        $this->resolvedSource = 'system-utc-fallback';
        return 0;
    }

    private function validTimezone(?string $timezone, string $fallback): string
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') {
            return $fallback;
        }

        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
