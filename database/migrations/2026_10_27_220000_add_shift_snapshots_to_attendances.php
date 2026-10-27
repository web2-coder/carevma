<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendances')) return;

        Schema::table('attendances', function (Blueprint $table) {
            if (!Schema::hasColumn('attendances', 'shift_start_snapshot')) {
                $table->dateTime('shift_start_snapshot')->nullable()->after('timezone_snapshot');
            }
            if (!Schema::hasColumn('attendances', 'shift_end_snapshot')) {
                $table->dateTime('shift_end_snapshot')->nullable()->after('shift_start_snapshot');
            }
        });

        if (!Schema::hasColumn('attendances', 'schedule_id') || !Schema::hasTable('employee_client_schedules')) return;

        DB::table('attendances as a')
            ->join('employee_client_schedules as s', 's.id', '=', 'a.schedule_id')
            ->whereNotNull('a.date')
            ->whereNull('a.shift_start_snapshot')
            ->select(['a.id', 'a.date', 'a.timezone_snapshot', 's.timezone', 's.start_time', 's.end_time'])
            ->orderBy('a.id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $timezone = $this->validTimezone($row->timezone_snapshot ?: $row->timezone);
                    if (!$timezone || !$row->start_time || !$row->end_time) continue;

                    try {
                        $start = Carbon::parse($row->date.' '.$row->start_time, $timezone);
                        $end = Carbon::parse($row->date.' '.$row->end_time, $timezone);
                        if ($end->lte($start)) $end->addDay();

                        DB::table('attendances')->where('id', $row->id)->update([
                            'shift_start_snapshot' => $start->utc()->format('Y-m-d H:i:s'),
                            'shift_end_snapshot' => $end->utc()->format('Y-m-d H:i:s'),
                        ]);
                    } catch (\Throwable) {
                        // Leave malformed legacy rows nullable; runtime keeps a safe fallback.
                    }
                }
            }, 'a.id', 'id');
    }

    public function down(): void
    {
        if (!Schema::hasTable('attendances')) return;
        Schema::table('attendances', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('attendances', 'shift_start_snapshot')) $columns[] = 'shift_start_snapshot';
            if (Schema::hasColumn('attendances', 'shift_end_snapshot')) $columns[] = 'shift_end_snapshot';
            if ($columns) $table->dropColumn($columns);
        });
    }

    private function validTimezone(?string $timezone): ?string
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') return null;
        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable) {
            return null;
        }
    }
};
