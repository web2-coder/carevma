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
Schema::create('invoices', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('client_id');
        $table->string('invoice_number')->unique();
        $table->date('period_start');
        $table->date('period_end');
        $table->decimal('total_hours', 8, 2)->default(0);
        $table->decimal('total_amount', 10, 2)->default(0);
        $table->timestamps();

        $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
    });

    Schema::table('employee_salaries', function (Blueprint $table) {
        $table->unsignedBigInteger('invoice_id')->nullable()->after('client_id');
        $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
        $table->dropColumn('invoice_number'); // remove old field
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
