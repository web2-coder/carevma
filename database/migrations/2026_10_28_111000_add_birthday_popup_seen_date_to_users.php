<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'birthday_popup_seen_date')) {
            Schema::table('users', function (Blueprint $table) {
                $table->date('birthday_popup_seen_date')->nullable()->after('last_seen_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'birthday_popup_seen_date')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('birthday_popup_seen_date');
            });
        }
    }
};
