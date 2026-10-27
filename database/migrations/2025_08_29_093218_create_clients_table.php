<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Legacy CareVMA originally tried to add foreign keys to services and
         * contract_types before those tables were created. On MySQL that leaves
         * a partially-created clients table and the migration fails.
         *
         * If the previous failed attempt already created the table, keep it and
         * let the following service/contract migrations attach the constraints.
         */
        if (Schema::hasTable('clients')) {
            return;
        }

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('business_name')->nullable();
            $table->string('country')->nullable();
            $table->string('phone_number')->nullable();
            $table->decimal('per_hour_charges', 10, 2)->nullable();
            $table->string('timezone')->nullable();

            // Parent tables are created by the next legacy migrations.
            // Keep the columns now; attach FKs only after the parents exist.
            $table->unsignedBigInteger('contract_type_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();

            $table->boolean('ring_center')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
