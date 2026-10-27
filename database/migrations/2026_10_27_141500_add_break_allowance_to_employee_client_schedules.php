<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employee_client_schedules')) return;

        if (!Schema::hasColumn('employee_client_schedules', 'break_allowed_hours')) {
            Schema::table('employee_client_schedules', function (Blueprint $table) {
                // H.MM notation: 0.30 = 30 minutes, 1.30 = 1 hour 30 minutes.
                $table->decimal('break_allowed_hours', 5, 2)->default(0.00)->after('no_of_hours');
            });
        }

        // Preserve the previously configured client break as the initial schedule value.
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'break_allowed_hours')) {
            DB::statement('UPDATE employee_client_schedules s JOIN clients c ON c.id = s.client_id SET s.break_allowed_hours = COALESCE(c.break_allowed_hours, 0.00) WHERE s.break_allowed_hours = 0.00');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_client_schedules') && Schema::hasColumn('employee_client_schedules', 'break_allowed_hours')) {
            Schema::table('employee_client_schedules', function (Blueprint $table) {
                $table->dropColumn('break_allowed_hours');
            });
        }
    }
};
