<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notifications') || Schema::hasColumn('notifications', 'notification_key')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            // NULL keeps all existing notifications and other notification types unchanged.
            // The unique index atomically prevents duplicate deliveries across cron processes.
            $table->string('notification_key', 100)->nullable();
            $table->unique(['user_id', 'notification_key'], 'carevma_notification_user_key_unique');
        });
    }

    public function down(): void
    {
        // Intentionally no destructive rollback of notification or delivery history.
    }
};
