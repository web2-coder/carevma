<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'sent_at')) $table->dateTime('sent_at')->nullable()->after('generated_at');
            if (!Schema::hasColumn('invoices', 'send_failed_at')) $table->dateTime('send_failed_at')->nullable()->after('sent_at');
            if (!Schema::hasColumn('invoices', 'send_error')) $table->text('send_error')->nullable()->after('send_failed_at');
        });
        if (!Schema::hasTable('invoice_generation_failures')) {
            Schema::create('invoice_generation_failures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->string('billing_month', 7);
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->text('error_message')->nullable();
                $table->dateTime('failed_at');
                $table->dateTime('resolved_at')->nullable();
                $table->timestamps();
                $table->unique(['client_id','billing_month']);
            });
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('invoice_generation_failures');
        Schema::table('invoices', function (Blueprint $table) {
            foreach (['send_error','send_failed_at','sent_at'] as $column) if (Schema::hasColumn('invoices', $column)) $table->dropColumn($column);
        });
    }
};
