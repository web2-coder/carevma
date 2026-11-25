<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Non-destructive: preserve all attendance rows, their billing snapshots,
        // and the original migration history. Index changes occur only here.
        $indexes = collect(Schema::getIndexes('attendances'))->pluck('name')->all();
        $newIndex = 'att_work_session_schedule_occurrence_unique';
        $oldIndex = 'att_work_session_schedule_unique';

        // Create the stronger occurrence-level protection BEFORE removing the
        // old restriction. Safe to retry if a preceding deploy was interrupted.
        if (!in_array($newIndex, $indexes, true)) {
            Schema::table('attendances', function (Blueprint $table) use ($newIndex) {
                $table->unique(
                    ['work_session_id', 'schedule_id', 'shift_start_snapshot'],
                    $newIndex
                );
            });
        }

        if (in_array($oldIndex, $indexes, true)) {
            Schema::table('attendances', function (Blueprint $table) use ($oldIndex) {
                $table->dropUnique($oldIndex);
            });
        }
    }

    public function down(): void
    {
        // Intentionally retain the occurrence-level index and records. Restoring
        // the old one-row-per-schedule unique index after multiple occurrences
        // have been recorded would fail or require deleting real attendance.
        // Rollback application code separately if required; never delete payroll data.
    }
};
