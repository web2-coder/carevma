<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Search/typeahead + list ordering.
        $this->addIndex('employees', ['first_name', 'id'], 'emp_first_id_idx');
        $this->addIndex('employees', ['last_name', 'id'], 'emp_last_id_idx');
        $this->addIndex('employees', ['timezone', 'id'], 'emp_tz_id_idx');
        $this->addIndex('employees', ['is_probation_email_sent', 'probation_end_date'], 'emp_prob_reminder_idx');
        $this->addIndex('clients', ['name', 'id'], 'client_name_id_idx');
        $this->addIndex('clients', ['business_name', 'id'], 'client_business_id_idx');
        $this->addIndex('clients', ['country', 'id'], 'client_country_id_idx');
        $this->addIndex('clients', ['timezone', 'id'], 'client_tz_id_idx');
        $this->addIndex('users', ['last_seen_at', 'id'], 'user_seen_id_idx');

        // Live Workforce / open-session lookups.
        $this->addIndex('work_sessions', ['employee_id', 'clock_out'], 'ws_emp_open_idx');
        $this->addIndex('attendances', ['date', 'clock_out', 'client_id'], 'att_date_open_client_idx');

        // Invoice due-time batch lookups.
        $this->addIndex('employee_client_schedules', ['enabled', 'schedule_date', 'client_id'], 'sched_enabled_date_client_idx');
        $this->addIndex('employee_client_schedules', ['enabled', 'weekday', 'client_id'], 'sched_enabled_week_client_idx');

        // Birthday duplicate-protection lookup.
        $this->addIndex('notifications', ['type', 'created_at', 'user_id'], 'notif_type_created_user_idx');
    }

    public function down(): void
    {
        // Intentionally non-destructive. Removing indexes from a large live database
        // can lock hot tables during rollback, so CareVMA keeps them in place.
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if (!Schema::hasTable($table)) return;
        foreach ($columns as $column) if (!Schema::hasColumn($table, $column)) return;

        try {
            $wanted = array_map('strtolower', $columns);
            foreach (Schema::getIndexes($table) as $index) {
                $existingName = strtolower((string) ($index['name'] ?? ''));
                $existingColumns = array_map('strtolower', (array) ($index['columns'] ?? []));
                if ($existingName === strtolower($name) || $existingColumns === $wanted) return;
            }
        } catch (\Throwable) {
            // Continue to guarded creation for drivers with limited introspection.
        }

        try {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            if (!str_contains($message, 'duplicate') && !str_contains($message, 'already exists')) throw $e;
        }
    }
};
