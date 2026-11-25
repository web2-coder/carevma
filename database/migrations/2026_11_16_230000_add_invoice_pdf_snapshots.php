<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('invoices', 'pdf_snapshot')) {
                    $table->json('pdf_snapshot')->nullable();
                }
                if (!Schema::hasColumn('invoices', 'snapshot_at')) {
                    $table->dateTime('snapshot_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('employee_salaries') && !Schema::hasColumn('employee_salaries', 'pdf_snapshot')) {
            Schema::table('employee_salaries', function (Blueprint $table) {
                $table->json('pdf_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Non-destructive rollback by design: issued invoice snapshots are financial history.
    }
};
