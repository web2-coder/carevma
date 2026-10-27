<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\EmployeeClientSchedule;
use Carbon\Carbon;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

/**
 * Canonical timezone gateway for CareVMA.
 *
 * Rules:
 * - persisted timestamp instants are UTC;
 * - client/doctor local time is the canonical business/display timezone for
 *   client-owned attendance, invoices and schedules;
 * - historical attendance keeps timezone_snapshot for calculation safety;
 * - IANA timezone names are always used so DST is resolved for the actual date.
 */
class ClientTimezoneService
{
    public function __construct(private TrustedTimeService $clock)
    {
    }

    public function businessTimezone(): string
    {
        return $this->valid(
            config('carevma.business_timezone', 'Asia/Karachi'),
            'UTC'
        );
    }

    public function valid(?string $timezone, string $fallback = 'UTC'): string
    {
        $candidate = trim((string) $timezone);
        if ($candidate !== '') {
            try {
                new DateTimeZone($candidate);
                return $candidate;
            } catch (\Throwable $e) {
                Log::warning('CareVMA ignored an invalid timezone.', [
                    'timezone' => $candidate,
                    'fallback' => $fallback,
                ]);
            }
        }

        if ($fallback !== $candidate) {
            try {
                new DateTimeZone($fallback);
                return $fallback;
            } catch (\Throwable) {
                // Fall through to UTC.
            }
        }

        return 'UTC';
    }

    public function forClient(Client|string|null $clientOrTimezone): string
    {
        if ($clientOrTimezone instanceof Client) {
            return $this->valid($clientOrTimezone->timezone, 'UTC');
        }

        return $this->valid($clientOrTimezone, 'UTC');
    }

    public function forSchedule(?EmployeeClientSchedule $schedule, ?Client $client = null): string
    {
        return $this->valid(
            $client?->timezone ?: $schedule?->client?->timezone ?: $schedule?->timezone,
            'UTC'
        );
    }

    public function forAttendance(Attendance $attendance, bool $preferCurrentClient = true): string
    {
        $currentClientZone = $attendance->client?->timezone;
        $snapshot = $attendance->timezone_snapshot;
        $scheduleZone = $attendance->schedule?->timezone;

        return $this->valid(
            $preferCurrentClient
                ? ($currentClientZone ?: $snapshot ?: $scheduleZone)
                : ($snapshot ?: $currentClientZone ?: $scheduleZone),
            'UTC'
        );
    }

    public function now(Client|string|null $clientOrTimezone = null): Carbon
    {
        $zone = $clientOrTimezone instanceof Client
            ? $this->forClient($clientOrTimezone)
            : $this->valid($clientOrTimezone, $this->businessTimezone());

        return $this->clock->now($zone);
    }

    public function utcNow(): Carbon
    {
        return $this->clock->now('UTC')->utc();
    }

    public function toZone(mixed $value, Client|string|null $clientOrTimezone, string $assume = 'UTC'): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $zone = $clientOrTimezone instanceof Client
            ? $this->forClient($clientOrTimezone)
            : $this->valid($clientOrTimezone, 'UTC');

        try {
            if ($value instanceof Carbon) {
                return $value->copy()->setTimezone($zone);
            }
            if ($value instanceof DateTimeInterface) {
                return Carbon::instance($value)->setTimezone($zone);
            }
            return Carbon::parse((string) $value, $this->valid($assume, 'UTC'))->setTimezone($zone);
        } catch (\Throwable $e) {
            Log::warning('CareVMA could not convert a date/time value.', [
                'value' => is_scalar($value) ? (string) $value : get_debug_type($value),
                'timezone' => $zone,
            ]);
            return null;
        }
    }

    public function utcFromLocalDateTime(string $date, string $time, Client|string $clientOrTimezone): Carbon
    {
        $zone = $clientOrTimezone instanceof Client
            ? $this->forClient($clientOrTimezone)
            : $this->valid($clientOrTimezone, 'UTC');

        return Carbon::createFromFormat('Y-m-d H:i', trim($date).' '.substr(trim($time), 0, 5), $zone)->utc();
    }

    /** @return array{0:Carbon,1:Carbon} */
    public function utcBoundsForLocalDates(string $from, string $to, Client|string $clientOrTimezone): array
    {
        $zone = $clientOrTimezone instanceof Client
            ? $this->forClient($clientOrTimezone)
            : $this->valid($clientOrTimezone, 'UTC');

        return [
            Carbon::parse($from, $zone)->startOfDay()->utc(),
            Carbon::parse($to, $zone)->endOfDay()->utc(),
        ];
    }


    /** @return array{0:?Carbon,1:?Carbon} */
    public function utcRangeForLocalFilters(?string $from, ?string $to, Client|string $clientOrTimezone): array
    {
        $zone = $clientOrTimezone instanceof Client
            ? $this->forClient($clientOrTimezone)
            : $this->valid($clientOrTimezone, 'UTC');

        $start = $from ? Carbon::parse($from, $zone)->startOfDay()->utc() : null;
        $end = $to ? Carbon::parse($to, $zone)->endOfDay()->utc() : null;

        return [$start, $end];
    }

    public function localDate(mixed $utcValue, Client|string $clientOrTimezone): ?string
    {
        return $this->toZone($utcValue, $clientOrTimezone, 'UTC')?->toDateString();
    }

    public function isoInZone(mixed $utcValue, Client|string $clientOrTimezone): ?string
    {
        return $this->toZone($utcValue, $clientOrTimezone, 'UTC')?->toIso8601String();
    }

    public function label(mixed $utcValue, Client|string $clientOrTimezone, string $format = 'M d, Y, g:i A'): ?string
    {
        return $this->toZone($utcValue, $clientOrTimezone, 'UTC')?->format($format);
    }
}
