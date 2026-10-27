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
        Schema::table('attendance_change_requests', function (Blueprint $table) {
            $table->time('old_clock_in')->nullable();   // only time, no default
            $table->time('old_clock_out')->nullable();  // only time, no default
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_change_requests', function (Blueprint $table) {
            //
        });
    }
};
