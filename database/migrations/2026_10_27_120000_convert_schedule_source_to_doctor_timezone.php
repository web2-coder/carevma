<?php

use App\Services\TrustedTimeService;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employee_client_schedules')
            || !Schema::hasColumn('employee_client_schedules', 'timezone')) {
            return;
        }

        $clock = app(TrustedTimeService::class);

        $rows = DB::table('employee_client_schedules as schedules')
            ->leftJoin('employees', 'employees.id', '=', 'schedules.employee_id')
            ->leftJoin('clients', 'clients.id', '=', 'schedules.client_id')
            ->select([
                'schedules.id',
                'schedules.weekday',
                'schedules.schedule_date',
                'schedules.start_time',
                'schedules.end_time',
                'schedules.repeat',
                'schedules.timezone',
                'employees.timezone as employee_timezone',
                'clients.timezone as client_timezone',
            ])
            ->orderBy('schedules.id')
            ->get();

        $dayNumbers = [
            'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6,
        ];

        foreach ($rows as $row) {
            $doctorTimezone = $this->validTimezone($row->client_timezone ?? null);
            if (!$doctorTimezone) {
                continue;
            }

            $oldTimezone = $this->validTimezone($row->timezone ?? null)
                ?: $this->validTimezone($row->employee_timezone ?? null)
                ?: $doctorTimezone;

            if ($oldTimezone === $doctorTimezone) {
                DB::table('employee_client_schedules')->where('id', $row->id)->update([
                    'timezone' => $doctorTimezone,
                ]);
                continue;
            }

            $weekday = ucfirst(strtolower(trim((string) $row->weekday)));
            if (!array_key_exists($weekday, $dayNumbers)) {
                continue;
            }

            try {
                if (!empty($row->schedule_date)) {
                    $baseDate = Carbon::parse((string) $row->schedule_date, $oldTimezone)->startOfDay();
                } else {
                    $now = $clock->now($oldTimezone);
                    $daysAhead = ($dayNumbers[$weekday] - $now->dayOfWeek + 7) % 7;
                    $baseDate = $now->copy()->addDays($daysAhead)->startOfDay();

                    $candidateStart = Carbon::parse($baseDate->toDateString().' '.$row->start_time, $oldTimezone);
                    if ($daysAhead === 0 && $candidateStart->lte($now)) {
                        $baseDate->addWeek();
                    }
                }

                $oldStart = Carbon::parse($baseDate->toDateString().' '.$row->start_time, $oldTimezone);
                $oldEnd = Carbon::parse($baseDate->toDateString().' '.$row->end_time, $oldTimezone);
                if ($oldEnd->lte($oldStart)) {
                    $oldEnd->addDay();
                }

                $doctorStart = $oldStart->copy()->setTimezone($doctorTimezone);
                $doctorEnd = $oldEnd->copy()->setTimezone($doctorTimezone);

                $update = [
                    'weekday' => $doctorStart->format('l'),
                    'start_time' => $doctorStart->format('H:i:s'),
                    'end_time' => $doctorEnd->format('H:i:s'),
                    'timezone' => $doctorTimezone,
                    'updated_at' => $clock->now('UTC')->toDateTimeString(),
                ];

                if (Schema::hasColumn('employee_client_schedules', 'schedule_date')
                    && !$row->repeat
                    && !empty($row->schedule_date)) {
                    $update['schedule_date'] = $doctorStart->toDateString();
                }

                DB::table('employee_client_schedules')->where('id', $row->id)->update($update);
            } catch (\Throwable) {
                // Leave malformed legacy rows unchanged rather than breaking deployment.
            }
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. After conversion, Doctor local time becomes
        // the business source of truth and cannot be losslessly reversed across DST.
    }

    private function validTimezone(?string $timezone): ?string
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') {
            return null;
        }

        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable) {
            return null;
        }
    }
};
