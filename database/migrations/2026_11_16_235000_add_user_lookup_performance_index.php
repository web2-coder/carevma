<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'name')) {
            return;
        }

        try {
            foreach (Schema::getIndexes('users') as $index) {
                $columns = array_map('strtolower', (array) ($index['columns'] ?? []));
                if ($columns === ['name'] || strtolower((string) ($index['name'] ?? '')) === 'users_name_lookup_idx') {
                    return;
                }
            }
        } catch (\Throwable) {
            // Continue to the guarded create below if this driver cannot introspect indexes.
        }

        try {
            Schema::table('users', function (Blueprint $table) {
                $table->index('name', 'users_name_lookup_idx');
            });
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            if (!str_contains($message, 'duplicate') && !str_contains($message, 'already exists')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        // Keep the index on rollback. Dropping an index from a large live users table can
        // create avoidable locking; the index is harmless to older application code.
    }
};
