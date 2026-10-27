<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_finance_entries')) {
            return;
        }

        Schema::create('company_finance_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->index();
            $table->enum('type', ['income', 'expense'])->index();
            $table->string('category', 120)->default('General');
            $table->decimal('amount', 14, 2);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_finance_entries');
    }
};
