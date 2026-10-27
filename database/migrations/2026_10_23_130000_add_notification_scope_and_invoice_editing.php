<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            Schema::table('notifications', function (Blueprint $table) {
                if (!Schema::hasColumn('notifications', 'user_id')) {
                    $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
                    $table->index(['user_id', 'is_read']);
                }
                if (!Schema::hasColumn('notifications', 'title')) {
                    $table->string('title')->nullable()->after('type');
                }
                if (!Schema::hasColumn('notifications', 'message')) {
                    $table->text('message')->nullable()->after('title');
                }
                if (!Schema::hasColumn('notifications', 'action_url')) {
                    $table->string('action_url', 500)->nullable()->after('message');
                }
                if (!Schema::hasColumn('notifications', 'read_at')) {
                    $table->dateTime('read_at')->nullable()->after('is_read');
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            $statusWasMissing = !Schema::hasColumn('invoices', 'status');

            Schema::table('invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('invoices', 'status')) {
                    $table->string('status', 30)->default('draft')->after('total_amount');
                }
                if (!Schema::hasColumn('invoices', 'auto_generated')) {
                    $table->boolean('auto_generated')->default(false)->after('status');
                }
                if (!Schema::hasColumn('invoices', 'generated_at')) {
                    $table->dateTime('generated_at')->nullable()->after('auto_generated');
                }
                if (!Schema::hasColumn('invoices', 'edited_by')) {
                    $table->foreignId('edited_by')->nullable()->after('generated_at')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('invoices', 'edited_at')) {
                    $table->dateTime('edited_at')->nullable()->after('edited_by');
                }
            });

            // Existing invoices pre-date the draft workflow and were already client-visible.
            // Preserve that behavior; only newly generated invoices start as draft.
            if ($statusWasMissing) {
                DB::table('invoices')->update(['status' => 'issued']);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Existing CareVMA notification/invoice history is preserved.
    }
};
