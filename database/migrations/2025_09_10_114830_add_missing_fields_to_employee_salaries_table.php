<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_salaries', 'total_late')) {
                $table->integer('total_late')->default(0)->after('total_hours');
            }

            if (!Schema::hasColumn('employee_salaries', 'total_overtime')) {
                $table->integer('total_overtime')->default(0)->after('total_late');
            }

            if (!Schema::hasColumn('employee_salaries', 'invoice_number')) {
                $table->string('invoice_number')->unique()->after('period_end');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->dropColumn(['total_late', 'total_overtime', 'invoice_number']);
        });
    }
};
