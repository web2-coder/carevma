<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            $driver = DB::getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE users MODIFY role ENUM('superadmin','admin','hr','client','employee') NOT NULL DEFAULT 'employee'");
            }
        }

        if (Schema::hasTable('clients') && !Schema::hasColumn('clients', 'profile_picture')) {
            Schema::table('clients', fn (Blueprint $t) => $t->string('profile_picture')->nullable()->after('user_id'));
        }

        if (Schema::hasTable('attendances')) {
            Schema::table('attendances', function (Blueprint $t) {
                if (!Schema::hasColumn('attendances', 'client_id')) {
                    $t->foreignId('client_id')->nullable()->after('employee_id')->constrained('clients')->nullOnDelete();
                }
                if (!Schema::hasColumn('attendances', 'schedule_id')) {
                    $t->foreignId('schedule_id')->nullable()->after('client_id')->constrained('employee_client_schedules')->nullOnDelete();
                }
                if (!Schema::hasColumn('attendances', 'timezone_snapshot')) {
                    $t->string('timezone_snapshot')->nullable()->after('date');
                }
                if (!Schema::hasColumn('attendances', 'overtime_status')) {
                    $t->string('overtime_status', 20)->default('none')->after('overtime');
                }
                if (!Schema::hasColumn('attendances', 'approved_overtime_minutes')) {
                    $t->unsignedInteger('approved_overtime_minutes')->default(0)->after('overtime_status');
                }
                if (!Schema::hasColumn('attendances', 'overtime_amount')) {
                    $t->decimal('overtime_amount', 10, 2)->nullable()->after('approved_overtime_minutes');
                }
                if (!Schema::hasColumn('attendances', 'overtime_approved_by')) {
                    $t->foreignId('overtime_approved_by')->nullable()->after('overtime_amount')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('attendances', 'overtime_approved_at')) {
                    $t->dateTime('overtime_approved_at')->nullable()->after('overtime_approved_by');
                }
            });

            // Legacy schema only allowed one attendance row per employee/day.
            // React edition supports multiple client sessions per day.
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                try {
                    DB::statement('ALTER TABLE attendances DROP INDEX attendances_employee_id_date_unique');
                } catch (\Throwable $e) {
                    // Index is already absent on repaired/newer databases.
                }
            }
        }

        if (Schema::hasTable('attendance_change_requests')) {
            Schema::table('attendance_change_requests', function (Blueprint $t) {
                if (!Schema::hasColumn('attendance_change_requests', 'reviewed_by')) {
                    $t->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('attendance_change_requests', 'reviewed_at')) {
                    $t->dateTime('reviewed_at')->nullable()->after('reviewed_by');
                }
                if (!Schema::hasColumn('attendance_change_requests', 'review_note')) {
                    $t->text('review_note')->nullable()->after('reviewed_at');
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. This migration is designed to preserve existing CareVMA data.
    }
};
