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
        Schema::create('employee_salaries', function (Blueprint $table) {
            
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('client_id');
                $table->decimal('total_hours', 8, 2);
                $table->decimal('salary_amount', 10, 2);
                $table->date('period_start');
                $table->date('period_end');
                $table->timestamps();
            
                $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
                $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
           
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('new_table_employee_salaries');
    }
};
