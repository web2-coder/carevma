<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Structural guard for resources/views after the blade-era purge. Needs no
 * database: it only asks whether every view a remaining template points at, and
 * every view live PHP renders, still exists on disk.
 */
class BladeViewsResolveTest extends TestCase
{
    /** Views rendered by live PHP (routes, controllers, mailables) as of the purge. */
    private const LIVE_ROOTS = [
        'spa',
        'client.auth.set-password',
        'employee.after_register.index',
        'employee.after_register.complete_profile',
        'employee.after_register.profile_done',
        'invoices.invoice',
        'emails.attendance.status',
        'emails.attendance_change_request',
        'emails.client-set-password',
        'emails.complete_profile',
        'emails.employee_invitation',
        'emails.invoice_notification',
        'emails.operational_activity',
        'emails.probation_ending',
    ];

    /**
     * Laravel renders resources/views/errors/{code}.blade.php on HTTP errors by
     * convention: no view() call names them, so a reference scan reports them as
     * orphans. They are not.
     */
    private const FRAMEWORK_RENDERED = [
        'errors.403', 'errors.404', 'errors.419', 'errors.429', 'errors.500', 'errors.503',
    ];

    /**
     * What survives of the admin template under public/assets: the files the live
     * layouts.app and the activation screen load, plus the two directories that
     * main.js, form-layouts.js and pages-auth-multisteps.js build paths into at
     * runtime. Everything else under public/assets was removed as unreferenced.
     */
    private const LIVE_STATIC_ASSETS = [
        'assets/css/demo.css',
        'assets/css/custom.css',
        'assets/js/main.js',
        'assets/js/config.js',
        'assets/js/form-layouts.js',
        'assets/js/forms-file-upload.js',
        'assets/js/pages-auth-multisteps.js',
        'assets/img/avatars/1.png',
        'assets/img/icons/payments',
        'assets/json/locales',
    ];

    public function test_static_assets_the_live_templates_load_still_exist(): void
    {
        foreach (self::LIVE_STATIC_ASSETS as $path) {
            $this->assertFileExists(public_path($path), "Live template asset [{$path}] is missing.");
        }
    }

    public function test_every_live_root_view_exists(): void
    {
        foreach (array_merge(self::LIVE_ROOTS, self::FRAMEWORK_RENDERED) as $view) {
            $this->assertTrue(View::exists($view), "Live view [{$view}] is missing.");
        }
    }

    public function test_no_remaining_template_points_at_a_missing_view(): void
    {
        $patterns = [
            '/@(?:extends|include|includeIf|component|each)\(\s*[\'"]([\w.\-]+)[\'"]/',
            '/@include(?:When|Unless)\(\s*[^,]+,\s*[\'"]([\w.\-]+)[\'"]/',
            '/<x-([\w.\-]+)/',
        ];

        $dangling = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = $file->getContents();
            $from = str_replace(DIRECTORY_SEPARATOR, '.', $file->getRelativePathname());

            foreach ($patterns as $index => $pattern) {
                preg_match_all($pattern, $source, $matches);
                foreach ($matches[1] as $target) {
                    $target = $index === 2 ? 'components.'.$target : $target;
                    if (str_contains($target, '::') || View::exists($target)) {
                        continue;
                    }
                    $dangling[] = "{$from} -> {$target}";
                }
            }
        }

        $this->assertSame([], $dangling, "Templates point at views that do not exist:\n".implode("\n", $dangling));
    }
}
