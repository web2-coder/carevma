<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && !Schema::hasColumn('invoices', 'billing_cycle_month')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->date('billing_cycle_month')->nullable()->after('period_end')->index();
            });
        }

        // Backfill older invoices so the invoice-month rule is immediately
        // consistent after deployment. Use each Doctor / Client timezone when
        // determining whether the invoice was generated after the 20th.
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'billing_cycle_month')) {
            DB::table('invoices')
                ->leftJoin('clients', 'clients.id', '=', 'invoices.client_id')
                ->whereNull('invoices.billing_cycle_month')
                ->select([
                    'invoices.id',
                    'invoices.generated_at',
                    'invoices.created_at',
                    'clients.timezone as client_timezone',
                ])
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        $zone = trim((string) ($row->client_timezone ?: 'UTC'));
                        try {
                            new \DateTimeZone($zone);
                        } catch (\Throwable) {
                            $zone = 'UTC';
                        }

                        $stamp = $row->generated_at ?: $row->created_at;
                        if (!$stamp) continue;

                        $local = Carbon::parse($stamp, 'UTC')->setTimezone($zone);
                        $month = ($local->day > 20 ? $local->copy()->addMonthNoOverflow() : $local->copy())
                            ->startOfMonth()
                            ->toDateString();

                        DB::table('invoices')->where('id', $row->id)->update(['billing_cycle_month' => $month]);
                    }
                }, 'invoices.id', 'id');
        }

        if (!Schema::hasTable('notebook_documents')) {
            Schema::create('notebook_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('title');
                $table->text('notes')->nullable();
                $table->string('file_path');
                $table->string('original_name');
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notebook_documents');

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'billing_cycle_month')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropIndex(['billing_cycle_month']);
                $table->dropColumn('billing_cycle_month');
            });
        }
    }
};
