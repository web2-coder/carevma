<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_adjustments')) {
            return;
        }

        Schema::create('invoice_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('direction', 20); // addition | deduction
            $table->string('category', 50); // bonus | allowance | reimbursement | other
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index(['invoice_id', 'direction']);
            $table->index(['invoice_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_adjustments');
    }
};
