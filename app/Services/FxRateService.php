<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FxRateService
{
    private const CACHE_KEY = 'carevma.fx.pkr_usd';

    public function pkrToUsd(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['rate']) && (float) $cached['rate'] > 0) {
            return $cached + ['cached' => true];
        }

        try {
            $response = Http::acceptJson()
                ->timeout(8)
                ->retry(1, 250)
                ->get('https://open.er-api.com/v6/latest/PKR')
                ->throw()
                ->json();

            $rate = (float) data_get($response, 'rates.USD', 0);
            if (($response['result'] ?? null) !== 'success' || $rate <= 0) {
                throw new RuntimeException('The exchange-rate provider returned an invalid PKR/USD rate.');
            }

            $payload = [
                'base' => 'PKR',
                'quote' => 'USD',
                'rate' => $rate,
                'source' => 'ExchangeRate-API',
                'updated_at' => isset($response['time_last_update_unix'])
                    ? date(DATE_ATOM, (int) $response['time_last_update_unix'])
                    : now()->toAtomString(),
                'next_update_at' => isset($response['time_next_update_unix'])
                    ? date(DATE_ATOM, (int) $response['time_next_update_unix'])
                    : null,
                'cached' => false,
            ];

            // The open endpoint updates daily; caching prevents one request per keystroke/user.
            Cache::put(self::CACHE_KEY, $payload, now()->addHours(24));

            return $payload;
        } catch (\Throwable $exception) {
            $fallback = (float) config('services.fx.pkr_usd_fallback', 0);
            if ($fallback > 0) {
                return [
                    'base' => 'PKR',
                    'quote' => 'USD',
                    'rate' => $fallback,
                    'source' => 'Configured fallback',
                    'updated_at' => now()->toAtomString(),
                    'next_update_at' => null,
                    'cached' => false,
                    'fallback' => true,
                ];
            }

            throw new RuntimeException(
                'Live PKR/USD rate is temporarily unavailable. You can enter USD manually or configure PKR_USD_FALLBACK_RATE.',
                previous: $exception
            );
        }
    }
}
