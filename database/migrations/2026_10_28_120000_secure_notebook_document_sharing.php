<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notebook_documents')) {
            return;
        }

        Schema::table('notebook_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('notebook_documents', 'recipient_user_id')) {
                $table->foreignId('recipient_user_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('users')
                    ->nullOnDelete()
                    ->index();
            }

            if (!Schema::hasColumn('notebook_documents', 'storage_disk')) {
                $table->string('storage_disk', 40)->default('public')->after('file_path');
            }

            if (!Schema::hasColumn('notebook_documents', 'shared_at')) {
                $table->timestamp('shared_at')->nullable()->after('file_size')->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('notebook_documents')) {
            return;
        }

        Schema::table('notebook_documents', function (Blueprint $table) {
            if (Schema::hasColumn('notebook_documents', 'recipient_user_id')) {
                $table->dropConstrainedForeignId('recipient_user_id');
            }
            if (Schema::hasColumn('notebook_documents', 'storage_disk')) {
                $table->dropColumn('storage_disk');
            }
            if (Schema::hasColumn('notebook_documents', 'shared_at')) {
                $table->dropColumn('shared_at');
            }
        });
    }
};
