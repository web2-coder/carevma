<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role')->index();
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->date('offboarded_at')->nullable()->after('date_of_regularisation');
            $table->string('offboarding_reason', 100)->nullable()->after('offboarded_at');
            $table->text('offboarding_notes')->nullable()->after('offboarding_reason');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->date('offboarded_at')->nullable();
            $table->string('offboarding_reason', 100)->nullable();
            $table->text('offboarding_notes')->nullable();
        });
        Schema::create('offboarding_events', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 20);
            $table->date('effective_date')->nullable();
            $table->string('reason', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboarding_events');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['offboarded_at','offboarding_reason','offboarding_notes']);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['offboarded_at','offboarding_reason','offboarding_notes']);
        });
        Schema::table('users', function (Blueprint $table) { $table->dropColumn('is_active'); });
    }
};
