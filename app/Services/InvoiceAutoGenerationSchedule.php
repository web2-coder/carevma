<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\EmployeeClientSchedule;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class InvoiceAutoGenerationSchedule
{
    public function __construct(private ClientTimezoneService $timezones)
    {
    }

    /**
     * Return the global automatic invoice release time for one billing period.
     *
     * Rule: wait until every enabled VA schedule that occurs on the cycle-end
     * local date has reached its scheduled end. If there are no enabled shifts on
     * that date, the safe release boundary is midnight immediately after the
     * cycle-end date; there is no configurable fallback clock.
     */
    public function dueAt(string $periodEnd): Carbon
    {
        $bust = (string) Cache::get('carevma:invoice-schedule-cache-bust', '0');
        $key = 'carevma:invoice-global-due-at:'.$periodEnd.':'.$bust;
        try {
            return Cache::remember($key, now()->addMinutes(5), fn () => $this->calculateGlobalDueAt($periodEnd));
        } catch (\Throwable) {
            return $this->calculateGlobalDueAt($periodEnd);
        }
    }

    private function calculateGlobalDueAt(string $periodEnd): Carbon
    {
        $latestShiftEndUtc = null;

        $weekday = Carbon::parse($periodEnd)->format('l');
        EmployeeClientSchedule::query()
            ->with('client:id,timezone')
            ->where('enabled', true)
            ->where(function ($query) use ($periodEnd, $weekday) {
                $query->where(function ($dated) use ($periodEnd) {
                    $dated->where('schedule_date', $periodEnd)
                        ->where(function ($repeat) {
                            $repeat->where('repeat', false)->orWhereNull('repeat');
                        });
                })->orWhere(function ($weekly) use ($weekday) {
                    $weekly->where('weekday', $weekday)
                        ->where(function ($repeat) {
                            $repeat->where('repeat', true)->orWhereNull('schedule_date');
                        });
                });
            })
            ->orderBy('id')
            ->chunkById(500, function ($schedules) use ($periodEnd, &$latestShiftEndUtc) {
                foreach ($schedules as $schedule) {
                    $endUtc = $this->scheduleEndForPeriodEnd($schedule, $periodEnd);
                    if ($endUtc && (!$latestShiftEndUtc || $endUtc->gt($latestShiftEndUtc))) {
                        $latestShiftEndUtc = $endUtc;
                    }
                }
            });

        return $latestShiftEndUtc ?: $this->noShiftDueAt($periodEnd);
    }

    public function forgetScheduleCaches(): void
    {
        // Period-specific keys are short-lived (5 minutes). Bump the namespace key
        // used by future additions without relying on cache-store wildcard deletes.
        Cache::put('carevma:invoice-schedule-cache-bust', now()->format('Uv'), now()->addDay());
    }

    /** Doctor-specific release time: final enabled VA shift for that Doctor on period end. */
    public function dueAtForClient(Client $client, string $periodEnd): Carbon
    {
        return $this->dueAtForClients(collect([$client]), $periodEnd)->get($client->id);
    }

    /** Resolve Doctor-specific due times with one schedule query for a whole batch. */
    public function dueAtForClients(Collection $clients, string $periodEnd): Collection
    {
        if ($clients->isEmpty()) return collect();
        $clientIds = $clients->pluck('id')->map(fn ($id) => (int) $id)->values();
        $weekday = Carbon::parse($periodEnd)->format('l');
        $schedulesByClient = EmployeeClientSchedule::query()
            ->where('enabled', true)
            ->whereIn('client_id', $clientIds)
            ->where(function ($query) use ($periodEnd, $weekday) {
                $query->where(function ($dated) use ($periodEnd) {
                    $dated->where('schedule_date', $periodEnd)
                        ->where(function ($repeat) { $repeat->where('repeat', false)->orWhereNull('repeat'); });
                })->orWhere(function ($weekly) use ($weekday) {
                    $weekly->where('weekday', $weekday)
                        ->where(function ($repeat) { $repeat->where('repeat', true)->orWhereNull('schedule_date'); });
                });
            })
            ->orderBy('id')
            ->get(['id', 'client_id', 'employee_id', 'weekday', 'start_time', 'end_time', 'timezone', 'repeat', 'schedule_date'])
            ->groupBy('client_id');

        return $clients->mapWithKeys(function (Client $client) use ($schedulesByClient, $periodEnd) {
            return [$client->id => $this->dueAtFromSchedules($client, $schedulesByClient->get($client->id, collect()), $periodEnd)];
        });
    }

    /**
     * Doctor-by-Doctor status for the dashboard popup.
     * Shows when each invoice can be released, whether a 25th shift/session is
     * still running, and whether an exact-cycle automatic invoice already exists.
     */
    public function doctorRunDetails(string $periodStart, string $periodEnd, ?Carbon $nowUtc = null): array
    {
        $clients = Client::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'business_name', 'timezone']);
        return $this->doctorRunDetailsForClients($clients, $periodStart, $periodEnd, $nowUtc);
    }

    /** Build invoice-run detail rows for only the requested Doctor page. */
    public function doctorRunDetailsForClients(Collection $clients, string $periodStart, string $periodEnd, ?Carbon $nowUtc = null): array
    {
        $nowUtc = ($nowUtc ?: Carbon::now('UTC'))->copy()->utc();
        $businessZone = $this->timezones->businessTimezone();
        if ($clients->isEmpty()) return [];

        $clients = $clients->values();
        $clientIds = $clients->pluck('id')->all();
        $weekday = Carbon::parse($periodEnd)->format('l');
        $schedulesByClient = EmployeeClientSchedule::query()
            ->where('enabled', true)
            ->whereIn('client_id', $clientIds)
            ->where(function ($query) use ($periodEnd, $weekday) {
                $query->where(function ($dated) use ($periodEnd) {
                    $dated->where('schedule_date', $periodEnd)
                        ->where(function ($repeat) { $repeat->where('repeat', false)->orWhereNull('repeat'); });
                })->orWhere(function ($weekly) use ($weekday) {
                    $weekly->where('weekday', $weekday)
                        ->where(function ($repeat) { $repeat->where('repeat', true)->orWhereNull('schedule_date'); });
                });
            })
            ->orderBy('id')
            ->get(['id', 'client_id', 'employee_id', 'weekday', 'start_time', 'end_time', 'timezone', 'repeat', 'schedule_date'])
            ->groupBy('client_id');

        // Modern attendance rows persist the Doctor-local date, so count them in SQL.
        $openCounts = Attendance::query()
            ->whereIn('client_id', $clientIds)
            ->whereNull('clock_out')
            ->where('date', $periodEnd)
            ->selectRaw('client_id, COUNT(*) as aggregate')
            ->groupBy('client_id')
            ->pluck('aggregate', 'client_id');

        // Legacy rows without date are rare; preserve correctness with a tiny fallback.
        $legacyOpen = Attendance::query()
            ->with('client:id,timezone')
            ->whereIn('client_id', $clientIds)
            ->whereNull('clock_out')
            ->whereNull('date')
            ->get(['id', 'client_id', 'date', 'shift_start_snapshot', 'timezone_snapshot'])
            ->filter(function (Attendance $attendance) use ($periodEnd) {
                $zone = $this->timezones->forAttendance($attendance, true);
                $date = $attendance->shift_start_snapshot?->copy()->setTimezone($zone)->toDateString();
                return $date === $periodEnd;
            })->groupBy('client_id')->map->count();
        foreach ($legacyOpen as $clientId => $count) $openCounts[$clientId] = (int) ($openCounts[$clientId] ?? 0) + (int) $count;

        $invoicesByClient = Invoice::query()
            ->whereIn('client_id', $clientIds)
            ->where('auto_generated', true)
            ->where('billing_cycle_month', Carbon::parse($periodEnd)->startOfMonth()->toDateString())
            ->where('period_end', $periodEnd)
            ->orderBy('id')
            ->get(['id', 'client_id', 'invoice_number', 'generated_at'])
            ->groupBy('client_id')
            ->map(fn (Collection $rows) => $rows->last());

        return $clients->map(function (Client $client) use ($periodEnd, $nowUtc, $businessZone, $schedulesByClient, $openCounts, $invoicesByClient) {
            $doctorZone = $this->timezones->forClient($client);
            $dueAt = $this->dueAtFromSchedules($client, $schedulesByClient->get($client->id, collect()), $periodEnd);
            $openAttendance = (int) ($openCounts[$client->id] ?? 0);
            $invoice = $invoicesByClient->get($client->id);
            $status = $invoice ? 'generated' : ($nowUtc->lt($dueAt) ? 'waiting_for_shift' : ($openAttendance > 0 ? 'waiting_for_checkout' : 'ready'));

            return [
                'client_id' => $client->id,
                'doctor_name' => $client->name ?: $client->business_name ?: 'Doctor',
                'business_name' => $client->business_name,
                'doctor_timezone' => $doctorZone,
                'admin_timezone' => $businessZone,
                'scheduled_for' => $dueAt->toIso8601String(),
                'doctor_local_time' => $dueAt->copy()->setTimezone($doctorZone)->toIso8601String(),
                'admin_local_time' => $dueAt->copy()->setTimezone($businessZone)->toIso8601String(),
                'open_cycle_end_attendance' => $openAttendance,
                'status' => $status,
                'invoice_id' => $invoice?->id,
                'invoice_number' => $invoice?->invoice_number,
                'generated_at' => $invoice?->generated_at?->toIso8601String(),
                'period_end' => $periodEnd,
            ];
        })->values()->all();
    }

    private function dueAtFromSchedules(Client $client, Collection $schedules, string $periodEnd): Carbon
    {
        $latestShiftEndUtc = null;
        foreach ($schedules as $schedule) {
            $schedule->setRelation('client', $client);
            $endUtc = $this->scheduleEndForPeriodEnd($schedule, $periodEnd);
            if ($endUtc && (!$latestShiftEndUtc || $endUtc->gt($latestShiftEndUtc))) {
                $latestShiftEndUtc = $endUtc;
            }
        }

        return $latestShiftEndUtc ?: $this->noShiftDueAt($periodEnd, $this->timezones->forClient($client));
    }

    /** Count still-open attendance rows belonging to the cycle-end local date. */
    public function openAttendanceCount(string $periodEnd): int
    {
        $count = Attendance::query()->whereNull('clock_out')->where('date', $periodEnd)->count();
        $legacy = Attendance::query()->with('client:id,timezone')->whereNull('clock_out')->whereNull('date')->get()
            ->filter(function (Attendance $attendance) use ($periodEnd) {
                $zone = $this->timezones->forAttendance($attendance, true);
                return $attendance->shift_start_snapshot?->copy()->setTimezone($zone)->toDateString() === $periodEnd;
            })->count();
        return $count + $legacy;
    }

    public function openAttendanceCountForClient(int $clientId, string $periodEnd): int
    {
        return (int) ($this->openAttendanceCountsForClients([$clientId], $periodEnd)[$clientId] ?? 0);
    }

    public function openAttendanceCountsForClients(array $clientIds, string $periodEnd): Collection
    {
        $clientIds = collect($clientIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if (!$clientIds) return collect();
        $counts = Attendance::query()->whereIn('client_id', $clientIds)->whereNull('clock_out')->where('date', $periodEnd)
            ->selectRaw('client_id, COUNT(*) as aggregate')->groupBy('client_id')->pluck('aggregate', 'client_id');
        $legacy = Attendance::query()->with('client:id,timezone')->whereIn('client_id', $clientIds)->whereNull('clock_out')->whereNull('date')->get()
            ->filter(function (Attendance $attendance) use ($periodEnd) {
                $zone = $this->timezones->forAttendance($attendance, true);
                return $attendance->shift_start_snapshot?->copy()->setTimezone($zone)->toDateString() === $periodEnd;
            })->groupBy('client_id')->map->count();
        foreach ($legacy as $clientId => $count) $counts[$clientId] = (int) ($counts[$clientId] ?? 0) + (int) $count;
        return $counts;
    }

    private function scheduleEndForPeriodEnd(EmployeeClientSchedule $schedule, string $periodEnd): ?Carbon
    {
        $zone = $this->timezones->forSchedule($schedule, $schedule->client);
        $day = Carbon::parse($periodEnd, $zone)->startOfDay();

        $matches = false;
        if (!$schedule->repeat && $schedule->schedule_date) {
            $matches = $schedule->schedule_date->toDateString() === $day->toDateString();
        } else {
            $matches = strcasecmp((string) $schedule->weekday, $day->format('l')) === 0;
        }

        if (!$matches) {
            return null;
        }

        $start = Carbon::parse($day->toDateString().' '.$schedule->start_time, $zone);
        $end = Carbon::parse($day->toDateString().' '.$schedule->end_time, $zone);
        if ($end->lte($start)) {
            $end->addDay();
        }

        return $end->copy()->utc();
    }

    private function noShiftDueAt(string $periodEnd, ?string $timezone = null): Carbon
    {
        $zone = $timezone ?: $this->timezones->businessTimezone();

        return Carbon::parse($periodEnd, $zone)->addDay()->startOfDay()->utc();
    }
}
