<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('services')) {
            Schema::create('services', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        // The clients table is intentionally created before this legacy table.
        // Add its FK only now that the parent exists. Ignore an already-present
        // constraint on upgraded/imported databases.
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'service_id')) {
            try {
                Schema::table('clients', function (Blueprint $table) {
                    $table->foreign('service_id')
                        ->references('id')
                        ->on('services')
                        ->nullOnDelete();
                });
            } catch (\Throwable $e) {
                // Constraint already exists or legacy DB already manages it.
            }
        }
    }

    public function down(): void
    {
        // Drop the child constraint first when possible.
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'service_id')) {
            try {
                Schema::table('clients', fn (Blueprint $table) => $table->dropForeign(['service_id']));
            } catch (\Throwable $e) {
            }
        }
        Schema::dropIfExists('services');
    }
};
