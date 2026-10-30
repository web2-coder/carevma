<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('work_sessions')) {
            Schema::create('work_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('primary_schedule_id')->nullable()->constrained('employee_client_schedules')->nullOnDelete();
                $table->dateTime('clock_in');
                $table->dateTime('clock_out')->nullable();
                $table->string('status', 30)->default('active');
                $table->text('check_in_comment')->nullable();
                $table->timestamps();
                $table->index(['employee_id', 'clock_out']);
                $table->index(['status', 'clock_out']);
            });
        }

        Schema::table('attendances', function (Blueprint $table) {
            if (!Schema::hasColumn('attendances', 'work_session_id')) {
                $table->foreignId('work_session_id')->nullable()->after('id')->constrained('work_sessions')->nullOnDelete();
            }
            if (!Schema::hasColumn('attendances', 'check_in_comment')) {
                $table->text('check_in_comment')->nullable()->after('clock_in');
            }
        });

        // Idempotency guard: one Doctor schedule segment per VA work session.
        try {
            Schema::table('attendances', function (Blueprint $table) {
                $table->unique(['work_session_id', 'schedule_id'], 'att_work_session_schedule_unique');
            });
        } catch (\Throwable) {
            // Safe on installations where an equivalent index already exists.
        }

        if (!Schema::hasTable('work_session_breaks')) {
            Schema::create('work_session_breaks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_session_id')->constrained('work_sessions')->cascadeOnDelete();
                $table->dateTime('start_time');
                $table->dateTime('end_time')->nullable();
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->index(['work_session_id', 'end_time']);
            });
        }

        Schema::table('employee_breaks', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_breaks', 'work_session_break_id')) {
                $table->foreignId('work_session_break_id')->nullable()->after('attendance_id')->constrained('work_session_breaks')->nullOnDelete();
            }
        });
        try {
            Schema::table('employee_breaks', function (Blueprint $table) {
                $table->unique(['attendance_id', 'work_session_break_id'], 'employee_break_session_mirror_unique');
            });
        } catch (\Throwable) {
        }

        Schema::table('attendance_change_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_change_requests', 'approved_clock_in')) {
                $table->time('approved_clock_in')->nullable()->after('requested_clock_out');
            }
            if (!Schema::hasColumn('attendance_change_requests', 'approved_clock_out')) {
                $table->time('approved_clock_out')->nullable()->after('approved_clock_in');
            }
        });

        if (!Schema::hasTable('schedule_change_requests')) {
            Schema::create('schedule_change_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('schedule_id')->nullable()->constrained('employee_client_schedules')->nullOnDelete();
                $table->date('requested_date')->nullable();
                $table->time('requested_start_time');
                $table->time('requested_end_time');
                $table->date('approved_date')->nullable();
                $table->time('approved_start_time')->nullable();
                $table->time('approved_end_time')->nullable();
                $table->text('reason');
                $table->string('status', 30)->default('pending');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->timestamps();
                $table->index(['employee_id', 'status']);
                $table->index(['schedule_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_change_requests');

        Schema::table('attendance_change_requests', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('attendance_change_requests', 'approved_clock_in')) $drop[] = 'approved_clock_in';
            if (Schema::hasColumn('attendance_change_requests', 'approved_clock_out')) $drop[] = 'approved_clock_out';
            if ($drop) $table->dropColumn($drop);
        });

        if (Schema::hasColumn('employee_breaks', 'work_session_break_id')) {
            Schema::table('employee_breaks', function (Blueprint $table) {
                try { $table->dropUnique('employee_break_session_mirror_unique'); } catch (\Throwable) {}
                $table->dropConstrainedForeignId('work_session_break_id');
            });
        }
        Schema::dropIfExists('work_session_breaks');

        if (Schema::hasColumn('attendances', 'work_session_id') || Schema::hasColumn('attendances', 'check_in_comment')) {
            Schema::table('attendances', function (Blueprint $table) {
                try { $table->dropUnique('att_work_session_schedule_unique'); } catch (\Throwable) {}
                if (Schema::hasColumn('attendances', 'work_session_id')) $table->dropConstrainedForeignId('work_session_id');
                if (Schema::hasColumn('attendances', 'check_in_comment')) $table->dropColumn('check_in_comment');
            });
        }
        Schema::dropIfExists('work_sessions');
    }
};
