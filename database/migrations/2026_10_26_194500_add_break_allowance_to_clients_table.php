<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'break_allowed_hours')) {
                $table->decimal('break_allowed_hours', 5, 2)->default(1.00)->after('per_hour_charges');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'break_allowed_hours')) {
                $table->dropColumn('break_allowed_hours');
            }
        });
    }
};
