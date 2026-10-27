<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\TrustedTimeService;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employee_client_schedules') || !Schema::hasTable('clients') || !Schema::hasTable('employees')) {
            return;
        }

        $rows = DB::table('employee_client_schedules')->orderBy('id')->get();
        $weekdayIndex = [
            'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6,
        ];

        foreach ($rows as $row) {
            $clientTimezone = trim((string) DB::table('clients')->where('id', $row->client_id)->value('timezone'));
            $employeeTimezone = trim((string) DB::table('employees')->where('id', $row->employee_id)->value('timezone'));
            $storedTimezone = trim((string) ($row->timezone ?? ''));

            if (!$this->validTimezone($clientTimezone)) {
                continue;
            }

            // A row already stored in the Doctor timezone needs no conversion.
            if ($storedTimezone === $clientTimezone) {
                continue;
            }

            $sourceTimezone = $this->validTimezone($storedTimezone)
                ? $storedTimezone
                : ($this->validTimezone($employeeTimezone) ? $employeeTimezone : null);

            if (!$sourceTimezone || empty($row->start_time) || empty($row->end_time)) {
                DB::table('employee_client_schedules')->where('id', $row->id)->update(['timezone' => $clientTimezone]);
                continue;
            }

            $weekday = ucfirst(strtolower(trim((string) ($row->weekday ?? ''))));
            if (!isset($weekdayIndex[$weekday])) {
                DB::table('employee_client_schedules')->where('id', $row->id)->update(['timezone' => $clientTimezone]);
                continue;
            }

            try {
                if (!empty($row->schedule_date)) {
                    $baseDate = Carbon::parse($row->schedule_date, $sourceTimezone)->startOfDay();
                } else {
                    $now = app(TrustedTimeService::class)->now($sourceTimezone)->startOfDay();
                    $daysAhead = ($weekdayIndex[$weekday] - $now->dayOfWeek + 7) % 7;
                    $baseDate = $now->copy()->addDays($daysAhead);
                }

                $start = Carbon::parse($baseDate->toDateString().' '.$row->start_time, $sourceTimezone);
                $end = Carbon::parse($baseDate->toDateString().' '.$row->end_time, $sourceTimezone);
                if ($end->lte($start)) {
                    $end->addDay();
                }

                $doctorStart = $start->copy()->setTimezone($clientTimezone);
                $doctorEnd = $end->copy()->setTimezone($clientTimezone);

                $updates = [
                    'weekday' => $doctorStart->format('l'),
                    'start_time' => $doctorStart->format('H:i:s'),
                    'end_time' => $doctorEnd->format('H:i:s'),
                    'timezone' => $clientTimezone,
                ];

                if (!empty($row->schedule_date)) {
                    $updates['schedule_date'] = $doctorStart->toDateString();
                }

                DB::table('employee_client_schedules')->where('id', $row->id)->update($updates);
            } catch (\Throwable) {
                // Keep the existing schedule intact if a legacy row cannot be parsed.
                // Admin/HR can edit that row normally after the deployment.
            }
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. Converting back could shift real-world
        // schedule instants, especially across daylight-saving transitions.
    }

    private function validTimezone(?string $timezone): bool
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') {
            return false;
        }

        try {
            new DateTimeZone($timezone);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
