<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employee_client_schedules') || Schema::hasColumn('employee_client_schedules', 'schedule_date')) {
            return;
        }

        Schema::table('employee_client_schedules', function (Blueprint $table) {
            $table->date('schedule_date')->nullable()->after('weekday');
            $table->index(['employee_id', 'schedule_date'], 'employee_schedule_date_index');
        });
    }

    public function down(): void
    {
        // Non-destructive by design for upgraded CareVMA installations.
    }
};
