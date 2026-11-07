<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attendance is the fastest-growing CareVMA table. These indexes match the
        // real list, dashboard, live-workforce, overtime and invoice-generation paths.
        $this->addIndex('attendances', ['clock_in'], 'att_clockin_idx');
        $this->addIndex('attendances', ['clock_out', 'clock_in'], 'att_open_clock_idx');
        $this->addIndex('attendances', ['client_id', 'clock_in'], 'att_client_clockin_idx');
        $this->addIndex('attendances', ['employee_id', 'clock_in'], 'att_emp_clockin_idx');
        $this->addIndex('attendances', ['client_id', 'date', 'id'], 'att_client_date_idx');
        $this->addIndex('attendances', ['schedule_id', 'date'], 'att_schedule_date_idx');
        $this->addIndex('attendances', ['overtime_status', 'date', 'id'], 'att_ot_status_date_idx');
        $this->addIndex('attendances', ['work_session_id', 'clock_out'], 'att_ws_open_idx');

        $this->addIndex('employee_breaks', ['attendance_id', 'end_time'], 'emp_break_att_end_idx');
        $this->addIndex('work_sessions', ['clock_out', 'clock_in'], 'ws_open_clock_idx');
        $this->addIndex('work_session_breaks', ['end_time', 'work_session_id'], 'ws_break_open_idx');

        $this->addIndex('employee_client_schedules', ['enabled', 'client_id'], 'sched_enabled_client_idx');
        $this->addIndex('employee_client_schedules', ['client_id', 'schedule_date'], 'sched_client_date_idx');
        $this->addIndex('employee_client_schedules', ['employee_id', 'schedule_date'], 'sched_emp_date_idx');

        $this->addIndex('invoices', ['billing_cycle_month', 'client_id'], 'inv_cycle_client_idx');
        $this->addIndex('invoices', ['client_id', 'period_end', 'status'], 'inv_client_end_status_idx');
        $this->addIndex('invoices', ['auto_generated', 'period_start', 'period_end'], 'inv_auto_period_idx');
        $this->addIndex('invoices', ['sent_at', 'client_id'], 'inv_sent_client_idx');

        $this->addIndex('employee_salaries', ['invoice_id', 'employee_id'], 'salary_inv_emp_idx');
        $this->addIndex('employee_salaries', ['client_id', 'employee_id'], 'salary_client_emp_idx');

        $this->addIndex('attendance_change_requests', ['status', 'created_at'], 'acr_status_created_idx');
        $this->addIndex('attendance_change_requests', ['employee_id', 'status', 'id'], 'acr_emp_status_idx');
        $this->addIndex('schedule_change_requests', ['status', 'created_at'], 'scr_status_created_idx');

        $this->addIndex('activity_logs', ['actor_role', 'created_at'], 'act_role_created_idx');
        $this->addIndex('activity_logs', ['subject_user_id', 'created_at'], 'act_subject_created_idx');

        $this->addIndex('notifications', ['user_id', 'is_read', 'created_at'], 'notif_user_read_created_idx');
        $this->addIndex('company_finance_entries', ['entry_date', 'id'], 'finance_date_id_idx');
    }

    public function down(): void
    {
        // Deliberately non-destructive. Dropping these indexes on a large live database
        // during an application rollback can lock hot tables and cause avoidable downtime.
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
        }

        // Laravel 12 exposes getIndexes across supported DB drivers. Skip both an index
        // with the same name and an equivalent index created by an older CareVMA patch.
        try {
            $wanted = array_map('strtolower', $columns);
            foreach (Schema::getIndexes($table) as $index) {
                $existingName = strtolower((string) ($index['name'] ?? ''));
                $existingColumns = array_map('strtolower', (array) ($index['columns'] ?? []));
                if ($existingName === strtolower($name) || $existingColumns === $wanted) {
                    return;
                }
            }
        } catch (\Throwable) {
            // If a driver cannot introspect indexes, the guarded create below remains safe.
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->index($columns, $name);
            });
        } catch (\Throwable $e) {
            // Some installations already have equivalent indexes under generated names.
            // Duplicate-index errors are safe to ignore; any other failure must surface.
            $message = strtolower($e->getMessage());
            if (!str_contains($message, 'duplicate') && !str_contains($message, 'already exists')) {
                throw $e;
            }
        }
    }
};
