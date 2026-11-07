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
        $settings['cycle_start_day'] = max(1, min(30, (int) ($settings['cycle_start_day'] ?? 26)));
        $settings['auto_generate'] = (bool) $settings['auto_generate'];

        return $settings;
    }

    public static function save(array $values): array
    {
        $values = array_intersect_key($values, self::defaults());
        if (array_key_exists('cycle_start_day', $values)) {
            $values['cycle_start_day'] = max(1, min(30, (int) $values['cycle_start_day']));
        }

        return Setting::put(self::KEY, $values + self::settings());
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

    /**
     * Resolve an invoice month (YYYY-MM) to the configured billing period.
     * The invoice month is always the month in which the billing period ends.
     * Examples: start day 26 + 2026-10 => 2026-09-26..2026-10-25;
     *           start day 24 + 2026-11 => 2026-10-24..2026-11-23.
     */
    public static function forInvoiceMonth(string $invoiceMonth): array
    {
        $month = Carbon::createFromFormat('!Y-m', $invoiceMonth);
        $day = self::startDay();

        $startMonth = $month->copy()->startOfMonth();
        if ($day > 1) {
            $startMonth->subMonthNoOverflow();
        }
        $start = self::boundaryForMonth($startMonth, $day);

        return [
            $start->toDateString(),
            self::endOf($start)->toDateString(),
        ];
    }

    /** Start (00:00, same timezone) of the cycle that is open on $date. */
    public static function openStart(Carbon $date): Carbon
    {
        $day = self::startDay();
        $thisMonth = self::boundaryForMonth($date->copy()->startOfMonth(), $day);
        if ($date->copy()->startOfDay()->lt($thisMonth)) {
            return self::boundaryForMonth($date->copy()->subMonthNoOverflow()->startOfMonth(), $day);
        }

        return $thisMonth;
    }

    /** [start, end] date strings of the cycle that most recently closed as of $date. */
    public static function latestCompleted(Carbon $date): array
    {
        $open = self::openStart($date);

        $previous = self::boundaryForMonth($open->copy()->subMonthNoOverflow()->startOfMonth(), self::startDay());

        return [
            $previous->toDateString(),
            $open->copy()->subDay()->toDateString(),
        ];
    }

    /** Last day of the cycle that starts at $openStart. */
    public static function endOf(Carbon $openStart): Carbon
    {
        return self::boundaryForMonth($openStart->copy()->addMonthNoOverflow()->startOfMonth(), self::startDay())->subDay();
    }

    /** When drafts for the cycle starting at $openStart are due: the next start day at the generation time. */
    public static function dueAfter(Carbon $openStart): Carbon
    {
        return self::boundaryForMonth($openStart->copy()->addMonthNoOverflow()->startOfMonth(), self::startDay())->setTimeFromTimeString(self::generationTime());
    }
    /** Resolve the configured day inside a month without overflowing short months (e.g. day 30 in February). */
    private static function boundaryForMonth(Carbon $month, int $day): Carbon
    {
        $base = $month->copy()->startOfMonth();
        $validDay = min($day, $base->daysInMonth);

        return $base->day($validDay)->startOfDay();
    }

}
