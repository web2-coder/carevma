<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One eligibility policy for invoice generation, draft synchronization and PDF snapshots. */
class InvoiceAttendanceScope
{
    public function __construct(private ClientTimezoneService $timezones) {}

    public function schedules(Client $client, $employeeIds): Collection
    {
        if (!Schema::hasTable('employee_client_schedules')) {
            return collect(); // Fail closed if assignment evidence is unavailable.
        }
        $ids = collect($employeeIds)->filter()->unique()->values()->all();
        if (!$ids) return collect();
        return DB::table('employee_client_schedules')
            ->where('client_id', $client->id)->whereIn('employee_id', $ids)
            ->get()->groupBy('employee_id');
    }

    public function valid(Attendance $record, Client $client, Collection $schedules): bool
    {
        if (!$record->clock_in || !$record->client_id || (int) $record->client_id !== (int) $client->id) return false;
        if ($schedules->isEmpty()) return false;

        if ($record->schedule_id) {
            // Historical disabled assignment is acceptable only when linked to this Doctor + VA.
            return $schedules->contains(fn ($s) => (int) $s->id === (int) $record->schedule_id
                && (int) $s->employee_id === (int) $record->employee_id
                && (int) $s->client_id === (int) $client->id);
        }

        $localDate = $this->timezones->localDate($record->clock_in, $client);
        $day = Carbon::parse($localDate)->format('l');
        return $schedules->contains(function ($schedule) use ($localDate, $day, $record, $client) {
            if ((int) $schedule->employee_id !== (int) $record->employee_id || (int) $schedule->client_id !== (int) $client->id) return false;
            if (isset($schedule->enabled) && !$schedule->enabled) return false;
            if (!empty($schedule->schedule_date)) return Carbon::parse($schedule->schedule_date)->toDateString() === $localDate;
            return strcasecmp((string) ($schedule->weekday ?? ''), $day) === 0;
        });
    }

    public function filter(Collection $records, Client $client, Collection $schedules): Collection
    {
        return $records->filter(fn (Attendance $row) => $this->valid($row, $client, $schedules))->values();
    }

    /** Find the first *assigned* check-in, not the first accidental client-linked attendance. */
    public function firstValidCheckIn(Client $client, int $employeeId, Collection $schedules): ?Carbon
    {
        if ($schedules->isEmpty()) return null;
        foreach (Attendance::query()->where('client_id', $client->id)
            ->where('employee_id', $employeeId)->whereNotNull('clock_in')
            ->orderBy('clock_in')->orderBy('id')->cursor() as $record) {
            if ($this->valid($record, $client, $schedules)) return $record->clock_in;
        }
        return null;
    }
}
