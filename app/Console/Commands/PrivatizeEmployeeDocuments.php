<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrivatizeEmployeeDocuments extends Command
{
    protected $signature = 'employee-documents:privatize
        {--dry-run : Report legacy public employee documents without changing files or rows}';

    protected $description = 'Move legacy employee onboarding documents from public storage to authenticated private storage';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $employeesChanged = 0;
        $documentsMoved = 0;
        $missing = 0;

        Employee::query()
            ->whereNotNull('upload_documents')
            ->orderBy('id')
            ->chunkById(100, function ($employees) use ($dryRun, &$employeesChanged, &$documentsMoved, &$missing) {
                foreach ($employees as $employee) {
                    $documents = Employee::documentPaths($employee->upload_documents);
                    if (!$documents) continue;

                    $next = $documents;
                    $copied = [];
                    $changed = false;

                    foreach ($documents as $index => $reference) {
                        if (Str::startsWith($reference, 'private:')) {
                            // If a previous run updated the database but the public
                            // delete failed, a later run can safely finish cleanup.
                            $privatePath = ltrim(substr($reference, strlen('private:')), '/');
                            if (Str::startsWith($privatePath, 'employees/documents/')
                                && Storage::disk('local')->exists($privatePath)
                                && Storage::disk('public')->exists($privatePath)) {
                                if ($dryRun) {
                                    $this->line("Would remove leftover public copy for employee {$employee->id}: {$privatePath}");
                                } else {
                                    Storage::disk('public')->delete($privatePath);
                                }
                            }
                            continue;
                        }

                        $publicPath = $this->legacyPublicPath($reference);
                        if (!$publicPath || !Storage::disk('public')->exists($publicPath)) {
                            $missing++;
                            $this->warn("Employee {$employee->id}: legacy document #{$index} was not found; reference kept unchanged.");
                            continue;
                        }

                        $privatePath = $publicPath;
                        if ($dryRun) {
                            $this->line("Would privatize employee {$employee->id}: {$publicPath}");
                            continue;
                        }

                        $contents = Storage::disk('public')->get($publicPath);
                        if (!Storage::disk('local')->put($privatePath, $contents)) {
                            $this->error("Employee {$employee->id}: failed to copy {$publicPath}; public file kept.");
                            continue;
                        }

                        if (!Storage::disk('local')->exists($privatePath)
                            || Storage::disk('local')->size($privatePath) !== Storage::disk('public')->size($publicPath)) {
                            Storage::disk('local')->delete($privatePath);
                            $this->error("Employee {$employee->id}: private copy verification failed for {$publicPath}; public file kept.");
                            continue;
                        }

                        $next[$index] = 'private:'.$privatePath;
                        $copied[] = $publicPath;
                        $changed = true;
                    }

                    if ($dryRun || !$changed) continue;

                    DB::transaction(function () use ($employee, $next) {
                        $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                        $locked->forceFill(['upload_documents' => array_values($next)])->save();
                    });

                    // Delete public originals only after the database points to the
                    // verified private copies. If deletion itself fails, no data is lost;
                    // the private route is already authoritative and the command can rerun.
                    foreach ($copied as $publicPath) {
                        Storage::disk('public')->delete($publicPath);
                        $documentsMoved++;
                    }
                    $employeesChanged++;
                }
            });

        $mode = $dryRun ? 'Dry run' : 'Complete';
        $this->info("{$mode}: {$employeesChanged} employee(s) updated, {$documentsMoved} document(s) privatized, {$missing} missing legacy file(s).");

        return self::SUCCESS;
    }

    private function legacyPublicPath(string $reference): ?string
    {
        $path = parse_url(trim($reference), PHP_URL_PATH) ?: trim($reference);
        $path = ltrim((string) $path, '/');
        foreach (['public/storage/', 'storage/'] as $prefix) {
            if (Str::startsWith($path, $prefix)) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        return Str::startsWith($path, 'employees/documents/') ? $path : null;
    }
}
