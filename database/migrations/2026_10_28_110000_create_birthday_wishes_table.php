<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('birthday_wishes')) {
            return;
        }

        Schema::create('birthday_wishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('birthday_date');
            $table->timestamps();
            $table->unique(['sender_user_id', 'recipient_employee_id', 'birthday_date'], 'birthday_wish_once_per_day');
            $table->index(['recipient_employee_id', 'birthday_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_wishes');
    }
};
