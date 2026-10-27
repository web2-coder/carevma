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
        Schema::create('attendance_change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('employee_id');
            $table->date('date'); // attendance date
            $table->time('requested_clock_in');   // only time, no default
            $table->time('requested_clock_out');  // only time, no default
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->timestamps();
    
            $table->foreign('attendance_id')->references('id')->on('attendances')->onDelete('cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_change_requests');
    }
};
