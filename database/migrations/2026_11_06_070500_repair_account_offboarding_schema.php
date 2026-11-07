<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', fn (Blueprint $table) => $table->boolean('is_active')->default(true)->after('role')->index());
        }
        foreach ([
            'offboarded_at' => fn (Blueprint $table) => $table->date('offboarded_at')->nullable(),
            'offboarding_reason' => fn (Blueprint $table) => $table->string('offboarding_reason', 100)->nullable(),
            'offboarding_notes' => fn (Blueprint $table) => $table->text('offboarding_notes')->nullable(),
        ] as $column => $add) {
            if (!Schema::hasColumn('employees', $column)) Schema::table('employees', $add);
            if (!Schema::hasColumn('clients', $column)) Schema::table('clients', $add);
        }
        if (!Schema::hasTable('offboarding_events')) {
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
    }

    public function down(): void
    {
        // Repair migration intentionally keeps existing offboarding data/schema on rollback.
    }
};
