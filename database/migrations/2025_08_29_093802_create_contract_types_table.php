<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contract_types')) {
            Schema::create('contract_types', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'contract_type_id')) {
            try {
                Schema::table('clients', function (Blueprint $table) {
                    $table->foreign('contract_type_id')
                        ->references('id')
                        ->on('contract_types')
                        ->nullOnDelete();
                });
            } catch (\Throwable $e) {
                // Constraint already exists or legacy DB already manages it.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'contract_type_id')) {
            try {
                Schema::table('clients', fn (Blueprint $table) => $table->dropForeign(['contract_type_id']));
            } catch (\Throwable $e) {
            }
        }
        Schema::dropIfExists('contract_types');
    }
};
