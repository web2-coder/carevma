<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees') || ! Schema::hasColumn('employees', 'break_allowed_hours')) {
            return;
        }

        DB::statement('ALTER TABLE `employees` MODIFY `break_allowed_hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00');
    }

    public function down(): void
    {
        if (! Schema::hasTable('employees') || ! Schema::hasColumn('employees', 'break_allowed_hours')) {
            return;
        }

        DB::statement('ALTER TABLE `employees` MODIFY `break_allowed_hours` DECIMAL(5,2) NOT NULL');
    }
};
