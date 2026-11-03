<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

/**
 * The monthly billing cycle, as configured on the admin Invoice Settings page.
 * A cycle starts on the chosen day and ends the day before that day next month.
 */
final class BillingCycle
{
    public const KEY = 'invoices';

    public static function defaults(): array
    {
        return ['cycle_start_day' => 26, 'generation_time' => '00:15', 'auto_generate' => true];
    }

    public static function settings(): array
    {
        $settings = Setting::fetch(self::KEY, self::defaults());
        $settings['cycle_start_day'] = min(28, max(1, (int) $settings['cycle_start_day']));
        $settings['auto_generate'] = (bool) $settings['auto_generate'];

        return $settings;
    }

    public static function save(array $values): array
    {
        return Setting::put(self::KEY, array_intersect_key($values, self::defaults()) + self::settings());
    }

    public static function startDay(): int
    {
        return self::settings()['cycle_start_day'];
    }

    public static function generationTime(): string
    {
        return self::settings()['generation_time'];
    }

    public static function autoGenerate(): bool
    {
        return self::settings()['auto_generate'];
    }

    /** Start (00:00, same timezone) of the cycle that is open on $date. */
    public static function openStart(Carbon $date): Carbon
    {
        $day = self::startDay();
        $start = $date->copy()->startOfDay()->day($day);
        if ($date->day < $day) {
            $start->subMonthNoOverflow()->day($day);
        }

        return $start;
    }

    /** [start, end] date strings of the cycle that most recently closed as of $date. */
    public static function latestCompleted(Carbon $date): array
    {
        $open = self::openStart($date);

        return [
            $open->copy()->subMonthNoOverflow()->day(self::startDay())->toDateString(),
            $open->copy()->subDay()->toDateString(),
        ];
    }

    /** Last day of the cycle that starts at $openStart. */
    public static function endOf(Carbon $openStart): Carbon
    {
        return $openStart->copy()->addMonthNoOverflow()->day(self::startDay())->subDay();
    }

    /** When drafts for the cycle starting at $openStart are due: the next start day at the generation time. */
    public static function dueAfter(Carbon $openStart): Carbon
    {
        return $openStart->copy()->addMonthNoOverflow()->day(self::startDay())->setTimeFromTimeString(self::generationTime());
    }
}
