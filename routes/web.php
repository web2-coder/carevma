<?php

use App\Http\Controllers\Api\AdminApiController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\DoctorApiController;
use App\Http\Controllers\Api\EmployeeApiController;
use App\Http\Controllers\Api\InvoicePdfController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Http\Controllers\Api\SearchApiController;
use App\Http\Controllers\Api\NotebookApiController;
use App\Http\Controllers\Auth\OrgGoogleController;
use App\Http\Controllers\Admin\Client\ClientAuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ActivityLogApiController;
use App\Http\Controllers\Api\InvoiceSettingsController;
use App\Http\Controllers\Api\RolePermissionsController;
use App\Http\Controllers\Api\BrandingSettingsController;
use App\Http\Middleware\OperationalActivityNotifier;

/*
|--------------------------------------------------------------------------
| JSON API - session authenticated, same-origin React SPA
|--------------------------------------------------------------------------
*/
Route::prefix('api')->group(function () {
    // Login is limited per email + IP (defined in AppServiceProvider); the two reset
    // endpoints per IP, since forgot-password is an outbound email relay.
    Route::get('/settings/public', [BrandingSettingsController::class, 'publicConfig']);
    Route::post('/login', [AuthApiController::class, 'login'])->middleware(['guest', 'throttle:login', OperationalActivityNotifier::class]);
    Route::post('/forgot-password', [AuthApiController::class, 'forgot'])->middleware(['guest', 'throttle:6,1']);
    Route::post('/reset-password', [AuthApiController::class, 'reset'])->middleware(['guest', 'throttle:6,1']);
    Route::get('/csrf-token', function (\Illuminate\Http\Request $request) {
        return response()
            ->json(['token' => $request->session()->token()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    });

    Route::middleware(['auth', 'account.active', OperationalActivityNotifier::class])->group(function () {
        Route::get('/settings/branding', [BrandingSettingsController::class, 'getBranding'])->middleware('module:branding');
        Route::put('/settings/branding', [BrandingSettingsController::class, 'updateBranding'])->middleware('module:branding,edit');
        Route::get('/settings/theme', [BrandingSettingsController::class, 'getTheme'])->middleware('module:branding');
        Route::get('/profile/theme-mode', [BrandingSettingsController::class, 'getPersonalThemeMode']);
        Route::put('/profile/theme-mode', [BrandingSettingsController::class, 'updatePersonalThemeMode'])->middleware('throttle:30,1');
        Route::put('/settings/theme', [BrandingSettingsController::class, 'updateTheme'])->middleware('module:branding,edit');
        Route::post('/settings/theme/reset', [BrandingSettingsController::class, 'resetTheme'])->middleware('module:branding,edit');
        Route::delete('/settings/logo', [BrandingSettingsController::class, 'removeMainLogo'])->middleware('module:branding,delete');
        Route::post('/settings/upload-logo', fn (\Illuminate\Http\Request $r) => app(BrandingSettingsController::class)->upload($r, $r->input('kind','logo_light')))->middleware('module:branding,edit');
        Route::post('/settings/upload-favicon', fn (\Illuminate\Http\Request $r) => app(BrandingSettingsController::class)->upload($r, 'favicon'))->middleware('module:branding,edit');
        Route::get('/profile/settings', [BrandingSettingsController::class, 'profile']);
        Route::post('/profile/avatar', [BrandingSettingsController::class, 'avatar']);
        Route::put('/profile', [BrandingSettingsController::class, 'updateProfile']);
        Route::put('/profile/password', [BrandingSettingsController::class, 'password'])->middleware('throttle:6,1');
        Route::get('/me', [AuthApiController::class, 'me']);
        Route::get('/time', [AuthApiController::class, 'time']);
        Route::post('/logout', [AuthApiController::class, 'logout']);
        Route::post('/birthday-popup/seen', [AuthApiController::class, 'markBirthdayPopupSeen']);

        Route::middleware('role:admin,hr')->group(function () {
            Route::get('/notebook', [NotebookApiController::class, 'index'])->middleware('module:notebook');
            Route::post('/notebook', [NotebookApiController::class, 'store'])->middleware('module:notebook,create');
            Route::delete('/notebook/{document}', [NotebookApiController::class, 'destroy'])->middleware('module:notebook,delete');
        });
        Route::get('/shared-documents', [NotebookApiController::class, 'sharedIndex']);
        Route::get('/shared-documents/{document}/download', [NotebookApiController::class, 'download']);

        Route::middleware('profile.complete')->group(function () {
            Route::get('/notifications', [NotificationApiController::class, 'index']);
            Route::get('/notifications/unread-count', [NotificationApiController::class, 'unreadCount']);
            Route::post('/notifications/read-all', [NotificationApiController::class, 'readAll']);
            Route::post('/notifications/{notification}/read', [NotificationApiController::class, 'read']);
            Route::get('/search', SearchApiController::class);
        });

        Route::middleware('role:admin,hr')->prefix('management')->group(function () {
            Route::get('/dashboard', [AdminApiController::class, 'dashboard']);
            Route::get('/invoice-run-details', [AdminApiController::class, 'invoiceRunDetails'])->middleware('module:invoices');
            Route::get('/invoice-settings', [InvoiceSettingsController::class, 'show']);
            Route::get('/live-users', [AdminApiController::class, 'liveUsers'])->middleware('module:live-users');
            Route::get('/live-count', [AdminApiController::class, 'liveCount'])->middleware('module:live-users');
            Route::get('/employees', [AdminApiController::class, 'employees'])->middleware('module:employees');
            Route::post('/employees', [AdminApiController::class, 'storeEmployee'])->middleware('module:employees,create');
            Route::post('/employees/{employee}/resend-invite', [AdminApiController::class, 'resendEmployeeInvitation'])->middleware('module:employees,edit');
            Route::get('/employees/{employee}', [AdminApiController::class, 'employee'])->middleware('module:employees');
            Route::put('/employees/{employee}', [AdminApiController::class, 'updateEmployee'])->middleware('module:employees,edit');
            Route::post('/employees/{employee}/probation', [AdminApiController::class, 'updateProbation'])->middleware('module:employees,edit');
            Route::get('/employees/{employee}/documents/{index}', [AdminApiController::class, 'downloadEmployeeDocument'])->whereNumber('index')->middleware('module:employees,export');
            Route::delete('/employees/{employee}', [AdminApiController::class, 'destroyEmployee'])->middleware('module:employees,delete');
            Route::post('/employees/{employee}/deactivate', [AdminApiController::class, 'deactivateEmployee'])->middleware('module:employees,edit');
            Route::post('/employees/{employee}/reactivate', [AdminApiController::class, 'reactivateEmployee'])->middleware('module:employees,edit');

            Route::get('/schedules', [AdminApiController::class, 'schedules'])->middleware('module:schedules');
            Route::post('/schedules', [AdminApiController::class, 'storeSchedule'])->middleware('module:schedules,create');
            Route::post('/schedules/batch', [AdminApiController::class, 'storeScheduleBatch'])->middleware('module:schedules,create');
            Route::put('/schedules/{schedule}', [AdminApiController::class, 'updateSchedule'])->middleware('module:schedules,edit');
            Route::delete('/schedules/{schedule}', [AdminApiController::class, 'destroySchedule'])->middleware('module:schedules,delete');

            Route::get('/attendance', [AdminApiController::class, 'attendance'])->middleware('module:attendance');
            Route::get('/change-requests', [AdminApiController::class, 'changeRequests'])->middleware('module:change-requests');
            Route::post('/change-requests/{changeRequest}/review', [AdminApiController::class, 'reviewChangeRequest'])->middleware('module:change-requests,review');
            Route::get('/schedule-change-requests', [AdminApiController::class, 'scheduleChangeRequests'])->middleware('module:change-requests');
            Route::post('/schedule-change-requests/{scheduleChangeRequest}/review', [AdminApiController::class, 'reviewScheduleChangeRequest'])->middleware('module:change-requests,review');
            Route::get('/overtime', [AdminApiController::class, 'overtime'])->middleware('module:overtime');
            Route::post('/overtime/{attendance}/review', [AdminApiController::class, 'reviewOvertime'])->middleware('module:overtime,review');

            Route::get('/references/{type}', [AdminApiController::class, 'references']);
            Route::post('/references/{type}', [AdminApiController::class, 'storeReference']);
            Route::put('/references/{type}/{id}', [AdminApiController::class, 'updateReference']);
            Route::delete('/references/{type}/{id}', [AdminApiController::class, 'destroyReference']);
            Route::get('/options', [AdminApiController::class, 'options']);
            Route::get('/people-options', [AdminApiController::class, 'peopleOptions']);
            Route::get('/fx/pkr-usd', [AdminApiController::class, 'pkrUsdRate']);

            Route::put('/account', [AdminApiController::class, 'updateAccount']);
            Route::post('/account', [AdminApiController::class, 'updateAccount']);
            Route::put('/account/password', [AdminApiController::class, 'changePassword'])->middleware('throttle:6,1');
        });

        // Every staff role may enter this group; each module checks its own grant (admins hold all).
        Route::middleware('role:admin,hr')->prefix('admin')->group(function () {
            Route::middleware('role:admin')->group(function () {
                Route::get('/roles', [RolePermissionsController::class, 'index']);
                Route::put('/roles/{role}', [RolePermissionsController::class, 'update']);
            });
            Route::get('/activity-logs', ActivityLogApiController::class)->middleware('module:activity-logs');
            Route::get('/hr-users', [AdminApiController::class, 'hrUsers'])->middleware('module:hr-team');
            Route::post('/hr-users', [AdminApiController::class, 'storeHrUser'])->middleware('module:hr-team,create');
            Route::put('/hr-users/{user}', [AdminApiController::class, 'updateHrUser'])->middleware('module:hr-team,edit');
            Route::delete('/hr-users/{user}', [AdminApiController::class, 'destroyHrUser'])->middleware('module:hr-team,delete');
            Route::get('/clients', [AdminApiController::class, 'clients'])->middleware('module:clients');
            Route::post('/clients', [AdminApiController::class, 'storeClient'])->middleware('module:clients,create');
            Route::get('/clients/{client}', [AdminApiController::class, 'client'])->middleware('module:clients');
            Route::put('/clients/{client}', [AdminApiController::class, 'updateClient'])->middleware('module:clients,edit');
            Route::delete('/clients/{client}', [AdminApiController::class, 'destroyClient'])->middleware('module:clients,delete');
            Route::post('/clients/{client}/resend-invite', [AdminApiController::class, 'resendClientInvitation'])->middleware('module:clients,edit');
            Route::post('/clients/{client}/deactivate', [AdminApiController::class, 'deactivateClient'])->middleware('module:clients,edit');
            Route::post('/clients/{client}/reactivate', [AdminApiController::class, 'reactivateClient'])->middleware('module:clients,edit');
            Route::get('/invoices', [AdminApiController::class, 'invoices'])->middleware('module:invoices');
            Route::get('/invoices-overview', [AdminApiController::class, 'invoiceOverview'])->middleware('module:invoices');
            Route::post('/invoices/generate', [AdminApiController::class, 'generateInvoice'])->middleware('module:invoices,create');
            Route::post('/invoices/bulk-delete', [AdminApiController::class, 'bulkDeleteInvoices'])->middleware('module:invoices,delete');
            Route::get('/invoice-settings', [InvoiceSettingsController::class, 'show'])->middleware('module:invoice-settings');
            Route::put('/invoice-settings', [InvoiceSettingsController::class, 'update'])->middleware('module:invoice-settings,edit');
            Route::get('/invoices/{invoice}', [AdminApiController::class, 'invoice'])->middleware('module:invoices');
            Route::put('/invoices/{invoice}', [AdminApiController::class, 'updateInvoice'])->middleware('module:invoices,edit');
            Route::delete('/invoices/{invoice}', [AdminApiController::class, 'destroyInvoice'])->middleware('module:invoices,delete');
            Route::post('/invoices/{invoice}/send', [AdminApiController::class, 'sendInvoicePdf'])->middleware('module:invoices,send');
            Route::get('/company-finance', [AdminApiController::class, 'companyFinance'])->middleware('module:company-finance');
            Route::post('/company-finance/entries', [AdminApiController::class, 'storeCompanyFinanceEntry'])->middleware('module:company-finance,create');
            Route::delete('/company-finance/entries/{entry}', [AdminApiController::class, 'destroyCompanyFinanceEntry'])->middleware('module:company-finance,delete');
        });

        Route::middleware('role:employee')->prefix('employee')->group(function () {
            // Profile endpoints remain available during first-login onboarding.
            Route::get('/profile', [EmployeeApiController::class, 'profile']);
            Route::post('/profile', [EmployeeApiController::class, 'updateProfile']);

            Route::middleware('profile.complete')->group(function () {
                Route::get('/dashboard', [EmployeeApiController::class, 'dashboard']);
                Route::get('/schedules', [EmployeeApiController::class, 'schedules']);
                Route::get('/attendance', [EmployeeApiController::class, 'attendance']);
                Route::get('/change-requests', [EmployeeApiController::class, 'changeRequests']);
                Route::post('/change-requests', [EmployeeApiController::class, 'requestChange']);
                Route::get('/schedule-change-requests', [EmployeeApiController::class, 'scheduleChangeRequests']);
                Route::post('/schedule-change-requests', [EmployeeApiController::class, 'requestScheduleChange']);
                Route::post('/clock-in', [EmployeeApiController::class, 'clockIn']);
                Route::post('/clock-out', [EmployeeApiController::class, 'clockOut']);
                Route::post('/overtime/{attendance}/request', [EmployeeApiController::class, 'requestOvertimeReview']);
                Route::post('/break/start', [EmployeeApiController::class, 'startBreak']);
                Route::post('/break/end', [EmployeeApiController::class, 'endBreak']);
                Route::put('/password', [EmployeeApiController::class, 'changePassword'])->middleware('throttle:6,1');
                Route::post('/birthday-wishes/{employee}', [EmployeeApiController::class, 'sendBirthdayWish']);
            });
        });

        Route::middleware('role:client')->prefix('doctor')->group(function () {
            Route::get('/dashboard', [DoctorApiController::class, 'dashboard']);
            Route::get('/employees/{employee}/attendance', [DoctorApiController::class, 'employeeAttendance']);
            Route::get('/profile', [DoctorApiController::class, 'profile']);
            Route::post('/profile', [DoctorApiController::class, 'updateProfile']);
            Route::put('/password', [DoctorApiController::class, 'changePassword'])->middleware('throttle:6,1');
        });
    });
});

/* Public profile photos only. Safe fallback for installations with an existing
   regular public/storage directory instead of a filesystem junction. Never serve
   notebook documents or any arbitrary storage path through this endpoint. */
Route::get('/storage/{owner}/profile_pictures/{filename}', function (string $owner, string $filename) {
    abort_unless(in_array($owner, ['users', 'employees', 'clients'], true), 404);
    abort_unless((bool) preg_match('/\A[a-zA-Z0-9_-]+\.(?:jpe?g|png|webp)\z/iD', $filename), 404);
    $relative = $owner.'/profile_pictures/'.$filename;
    abort_unless(\Illuminate\Support\Facades\Storage::disk('public')->exists($relative), 404);
    return response()->file(\Illuminate\Support\Facades\Storage::disk('public')->path($relative), [
        'Cache-Control' => 'public, max-age=300',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->name('public.profile-photo');

/* PDF remains a server-rendered document; navigation inside the app stays SPA-only. */
Route::get('/invoices/{invoice}/pdf', InvoicePdfController::class)->middleware(['auth', 'account.active'])->name('invoices.pdf');

/* Signed client set-password and Google OAuth URLs stay server-side so old emails and integrations keep working.
   VA activation now goes through the SPA (/reset-password/{token}?invite=1); the old /complete-profile flow is retired
   and its URLs fall through to the React shell below. */
Route::get('/client/set-password', [ClientAuthController::class, 'showSetPasswordForm'])->name('client.password.form')->middleware('signed');
Route::post('/client/set-password', [ClientAuthController::class, 'setPassword'])->name('client.password.update')->middleware('throttle:6,1');
Route::get('/google/connect', [OrgGoogleController::class, 'redirectToGoogle'])->middleware(['auth', 'account.active', 'role:employee'])->name('google.connect');
Route::get('/google/callback', [OrgGoogleController::class, 'handleGoogleCallback'])->name('google.callback');
Route::get('/auth/google/redirect', [OrgGoogleController::class, 'redirectToGoogleLogin'])->name('google.login');
Route::post('/google/disconnect', [OrgGoogleController::class, 'disconnectGoogle'])->middleware(['auth', 'account.active', 'role:employee'])->name('google.disconnect');

Route::get('/reset-password/{token}', fn () => view('spa'))->name('password.reset');

/* Named SPA entry routes keep legacy redirects, middleware and old email links valid. */
Route::get('/login', fn () => view('spa'))->name('login');
Route::get('/admin/dashboard', fn () => view('spa'))->name('admin.dashboard');
Route::get('/admin/invoices', fn () => view('spa'))->name('admin.invoices');
Route::get('/admin/company-finance', fn () => view('spa'))->name('admin.company-finance');
Route::get('/admin/client/{id}/profile', fn () => view('spa'))->name('client.profile');
Route::get('/employee/dashboard', fn () => view('spa'))->name('employee.dashboard');
Route::get('/employee/settings', fn () => view('spa'))->name('employee.setting');
Route::get('/employee/dashboard/profile/edit', fn () => view('spa'))->name('employee.profile.edit');
Route::get('/doctor/dashboard', fn () => view('spa'))->name('doctor.dashboard');

/* React Router owns every portal URL. Direct refresh returns the same SPA shell. */
Route::get('/{any?}', fn () => view('spa'))
    ->where('any', '^(?!api(?:/|$)|storage(?:/|$)|build(?:/|$)|up$).*$')
    ->name('spa');
