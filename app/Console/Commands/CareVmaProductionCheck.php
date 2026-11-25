<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only operational checks. No job processing, SQL writes or migrations.
 */
class CareVmaProductionCheck extends Command
{
    protected $signature = 'carevma:production-check {--strict : Exit nonzero when warnings are present}';

    protected $description = 'Read-only CareVMA database, queue, invoice and public-photo readiness checks';

    public function handle(): int
    {
        $warnings = 0;
        $errors = 0;
        $ok = function (string $message): void { $this->line('<info>[OK]</info> '.$message); };
        $warn = function (string $message) use (&$warnings): void {
            $warnings++;
            $this->line('<comment>[WARN]</comment> '.$message);
        };
        $error = function (string $message) use (&$errors): void {
            $errors++;
            $this->line('<error>[ERROR]</error> '.$message);
        };

        $this->info('CareVMA production readiness - READ ONLY');

        if (app()->environment('production') && (bool) config('app.debug')) {
            $error('APP_DEBUG is enabled in production. Disable it in server configuration.');
        } elseif (app()->environment('production')) {
            $ok('Production debug is disabled.');
        } else {
            $this->line('[INFO] Environment: '.app()->environment().' (local debug is not a production finding).');
        }

        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            $ok('Database responds to read-only SELECT.');
        } catch (\Throwable $e) {
            $error('Database connection/read failed: '.$e::class.'. Check configuration and server logs.');
            $this->line('Result: '.$errors.' error(s), '.$warnings.' warning(s).');
            return self::FAILURE;
        }

        try {
            $queue = (string) config('queue.default', 'sync');
            $this->line('[INFO] Queue connection: '.$queue);
            if ($queue === 'sync') {
                $warn('Queue is synchronous; email and notifications may hold request workers.');
            }
            if ($queue === 'database') {
                if (!Schema::hasTable('jobs')) {
                    $error('Database queue uses jobs table, but jobs table is absent.');
                } else {
                    $pending = DB::table('jobs')->count();
                    $ok('Queue jobs table exists; pending: '.$pending.'.');
                    if ($pending > 1000) $warn('More than 1000 pending jobs; verify queue worker health.');
                }
            }
            if (Schema::hasTable('failed_jobs')) {
                $failed = DB::table('failed_jobs')->count();
                $this->line('[INFO] Failed jobs: '.$failed.'.');
                if ($failed > 0) $warn('Failed jobs exist; inspect php artisan queue:failed before any retry.');
            } else {
                $warn('failed_jobs table does not exist; failed queue tasks may not be recorded in database.');
            }
            if (Schema::hasTable('invoice_generation_failures')) {
                $unresolved = DB::table('invoice_generation_failures')->whereNull('resolved_at')->count();
                $this->line('[INFO] Unresolved invoice generation failures: '.$unresolved.'.');
                if ($unresolved > 0) $warn('Unresolved invoice generation failures exist; inspect invoice-run details.');
            } else {
                $warn('Invoice generation monitoring table not found.');
            }
        } catch (\Throwable $e) {
            $error('Operational SQL checks could not complete: '.$e::class.'.');
        }

        $publicStorage = public_path('storage');
        $appStorage = storage_path('app/public');
        if (!is_dir($appStorage)) {
            $warn('storage/app/public directory missing. Profile photo uploads may fail.');
        } elseif (is_link($publicStorage)) {
            $ok('public/storage is a symbolic link.');
        } elseif (is_dir($publicStorage)) {
            $warn('public/storage is an ordinary directory, not a symlink. New uploaded images may not appear on the public path.');
        } else {
            $warn('public/storage link is absent. Run storage:link only after checking hosting/storage permissions.');
        }

        $this->line('Result: '.$errors.' error(s), '.$warnings.' warning(s). No data changed.');
        return ($errors > 0 || ((bool) $this->option('strict') && $warnings > 0))
            ? self::FAILURE : self::SUCCESS;
    }
}
