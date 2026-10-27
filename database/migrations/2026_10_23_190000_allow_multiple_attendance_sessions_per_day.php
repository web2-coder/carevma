<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendances')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // A non-unique index must exist before the legacy unique index is removed,
            // because MySQL may be using the old composite index to support employee_id's FK.
            $normalIndex = DB::select("SHOW INDEX FROM attendances WHERE Key_name = 'attendances_employee_date_index'");
            if (!$normalIndex) {
                Schema::table('attendances', function (Blueprint $table) {
                    $table->index(['employee_id', 'date'], 'attendances_employee_date_index');
                });
            }

            $legacyUnique = DB::select("SHOW INDEX FROM attendances WHERE Key_name = 'attendances_employee_id_date_unique'");
            if ($legacyUnique) {
                DB::statement('ALTER TABLE attendances DROP INDEX attendances_employee_id_date_unique');
            }

            return;
        }

        // Best-effort compatibility for other database engines used in local tests.
        try {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropUnique('attendances_employee_id_date_unique');
            });
        } catch (Throwable $e) {
            // Already removed or the engine uses a different generated name.
        }

        try {
            Schema::table('attendances', function (Blueprint $table) {
                $table->index(['employee_id', 'date'], 'attendances_employee_date_index');
            });
        } catch (Throwable $e) {
            // Index already exists.
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: never restore the one-session-per-day restriction.
    }
};
