<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\EmployeeClientSchedule;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
            ->chunkById(200, function ($schedules) use ($periodEnd, &$latestShiftEndUtc) {
                foreach ($schedules as $schedule) {
                    $endUtc = $this->scheduleEndForPeriodEnd($schedule, $periodEnd);
                    if (!$endUtc) {
                        continue;
                    }
                    if (!$latestShiftEndUtc || $endUtc->gt($latestShiftEndUtc)) {
                        $latestShiftEndUtc = $endUtc;
                    }
                }
            });

        if ($latestShiftEndUtc) {
            return $latestShiftEndUtc;
        }

        return $this->noShiftDueAt($periodEnd);
    }

    /** Doctor-specific release time: final enabled VA shift for that Doctor on period end. */
    public function dueAtForClient(Client $client, string $periodEnd): Carbon
    {
        $latestShiftEndUtc = null;

        EmployeeClientSchedule::query()
            ->where('enabled', true)
            ->where('client_id', $client->id)
            ->orderBy('id')
            ->get()
            ->each(function ($schedule) use ($periodEnd, &$latestShiftEndUtc, $client) {
                $schedule->setRelation('client', $client);
                $endUtc = $this->scheduleEndForPeriodEnd($schedule, $periodEnd);
                if ($endUtc && (!$latestShiftEndUtc || $endUtc->gt($latestShiftEndUtc))) {
                    $latestShiftEndUtc = $endUtc;
                }
            });

        return $latestShiftEndUtc ?: $this->noShiftDueAt($periodEnd, $this->timezones->forClient($client));
    }

    /**
     * Doctor-by-Doctor status for the dashboard popup.
     * Shows when each invoice can be released, whether a 25th shift/session is
     * still running, and whether an exact-cycle automatic invoice already exists.
     */
    public function doctorRunDetails(string $periodStart, string $periodEnd, ?Carbon $nowUtc = null): array
    {
        $nowUtc = ($nowUtc ?: Carbon::now('UTC'))->copy()->utc();
        $businessZone = $this->timezones->businessTimezone();
        $clients = Client::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'business_name', 'timezone']);

        if ($clients->isEmpty()) {
            return [];
        }

        $clientIds = $clients->pluck('id')->all();
        $weekday = Carbon::parse($periodEnd)->format('l');

        // One schedule query for every Doctor instead of one query per Doctor.
        // Only rows that could occur on periodEnd are hydrated.
        $schedulesByClient = EmployeeClientSchedule::query()
            ->where('enabled', true)
            ->whereIn('client_id', $clientIds)
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
            ->get(['id', 'client_id', 'employee_id', 'weekday', 'start_time', 'end_time', 'timezone', 'repeat', 'schedule_date'])
            ->groupBy('client_id');

        // Open rows are normally a very small set. Load them once, then preserve the
        // exact timezone/date rule that the old per-Doctor query used.
        $clientsById = $clients->keyBy('id');
        $openCounts = Attendance::query()
            ->whereIn('client_id', $clientIds)
            ->whereNull('clock_out')
            ->get(['id', 'client_id', 'date', 'shift_start_snapshot', 'timezone_snapshot'])
            ->filter(function (Attendance $attendance) use ($periodEnd, $clientsById) {
                if ($client = $clientsById->get($attendance->client_id)) {
                    $attendance->setRelation('client', $client);
                }
                $zone = $this->timezones->forAttendance($attendance, true);
                $date = $attendance->date?->toDateString();
                if (!$date && $attendance->shift_start_snapshot) {
                    $date = $attendance->shift_start_snapshot->copy()->setTimezone($zone)->toDateString();
                }
                return $date === $periodEnd;
            })
            ->groupBy('client_id')
            ->map->count();

        // One invoice query for the complete popup instead of N individual lookups.
        $invoicesByClient = Invoice::query()
            ->whereIn('client_id', $clientIds)
            ->where('auto_generated', true)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->orderBy('id')
            ->get(['id', 'client_id', 'invoice_number', 'generated_at'])
            ->groupBy('client_id')
            ->map(fn (Collection $rows) => $rows->last());

        return $clients->map(function (Client $client) use (
            $periodEnd,
            $nowUtc,
            $businessZone,
            $schedulesByClient,
            $openCounts,
            $invoicesByClient
        ) {
            $doctorZone = $this->timezones->forClient($client);
            $dueAt = $this->dueAtFromSchedules(
                $client,
                $schedulesByClient->get($client->id, collect()),
                $periodEnd
            );
            $openAttendance = (int) ($openCounts[$client->id] ?? 0);
            $invoice = $invoicesByClient->get($client->id);

            if ($invoice) {
                $status = 'generated';
            } elseif ($nowUtc->lt($dueAt)) {
                $status = 'waiting_for_shift';
            } elseif ($openAttendance > 0) {
                $status = 'waiting_for_checkout';
            } else {
                $status = 'ready';
            }

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
        return Attendance::query()
            ->with('client:id,timezone')
            ->whereNull('clock_out')
            ->get()
            ->filter(function (Attendance $attendance) use ($periodEnd) {
                $zone = $this->timezones->forAttendance($attendance, true);
                $date = $attendance->date?->toDateString();
                if (!$date && $attendance->shift_start_snapshot) {
                    $date = $attendance->shift_start_snapshot->copy()->setTimezone($zone)->toDateString();
                }
                return $date === $periodEnd;
            })
            ->count();
    }

    public function openAttendanceCountForClient(int $clientId, string $periodEnd): int
    {
        return Attendance::query()
            ->with('client:id,timezone')
            ->where('client_id', $clientId)
            ->whereNull('clock_out')
            ->get()
            ->filter(function (Attendance $attendance) use ($periodEnd) {
                $zone = $this->timezones->forAttendance($attendance, true);
                $date = $attendance->date?->toDateString();
                if (!$date && $attendance->shift_start_snapshot) {
                    $date = $attendance->shift_start_snapshot->copy()->setTimezone($zone)->toDateString();
                }
                return $date === $periodEnd;
            })
            ->count();
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
