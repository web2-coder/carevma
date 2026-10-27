<?php

use App\Http\Controllers\Api\AdminApiController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\DoctorApiController;
use App\Http\Controllers\Api\EmployeeApiController;
use App\Http\Controllers\Api\InvoicePdfController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Http\Controllers\Auth\OrgGoogleController;
use App\Http\Controllers\Admin\Client\ClientAuthController;
use App\Http\Controllers\EmployeeProfile\CompleteProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\OperationalActivityNotifier;

/*
|--------------------------------------------------------------------------
| JSON API - session authenticated, same-origin React SPA
|--------------------------------------------------------------------------
*/
Route::prefix('api')->group(function () {
    Route::post('/login', [AuthApiController::class, 'login'])->middleware('guest');
    Route::post('/forgot-password', [AuthApiController::class, 'forgot'])->middleware('guest');
    Route::post('/reset-password', [AuthApiController::class, 'reset'])->middleware('guest');

    Route::middleware(['auth', OperationalActivityNotifier::class])->group(function () {
        Route::get('/me', [AuthApiController::class, 'me']);
        Route::get('/time', [AuthApiController::class, 'time']);
        Route::post('/logout', [AuthApiController::class, 'logout']);

        Route::middleware('profile.complete')->group(function () {
            Route::get('/notifications', [NotificationApiController::class, 'index']);
            Route::get('/notifications/unread-count', [NotificationApiController::class, 'unreadCount']);
            Route::post('/notifications/read-all', [NotificationApiController::class, 'readAll']);
            Route::post('/notifications/{notification}/read', [NotificationApiController::class, 'read']);
        });

        Route::middleware('role:admin,hr')->prefix('management')->group(function () {
            Route::get('/dashboard', [AdminApiController::class, 'dashboard']);
            Route::get('/live-users', [AdminApiController::class, 'liveUsers']);
            Route::get('/employees', [AdminApiController::class, 'employees']);
            Route::post('/employees', [AdminApiController::class, 'storeEmployee']);
            Route::post('/employees/{employee}/resend-invite', [AdminApiController::class, 'resendEmployeeInvitation']);
            Route::get('/employees/{employee}', [AdminApiController::class, 'employee']);
            Route::put('/employees/{employee}', [AdminApiController::class, 'updateEmployee']);
            Route::delete('/employees/{employee}', [AdminApiController::class, 'destroyEmployee']);

            Route::get('/schedules', [AdminApiController::class, 'schedules']);
            Route::post('/schedules', [AdminApiController::class, 'storeSchedule']);
            Route::put('/schedules/{schedule}', [AdminApiController::class, 'updateSchedule']);
            Route::delete('/schedules/{schedule}', [AdminApiController::class, 'destroySchedule']);

            Route::get('/attendance', [AdminApiController::class, 'attendance']);
            Route::get('/change-requests', [AdminApiController::class, 'changeRequests']);
            Route::post('/change-requests/{changeRequest}/review', [AdminApiController::class, 'reviewChangeRequest']);
            Route::get('/overtime', [AdminApiController::class, 'overtime']);
            Route::post('/overtime/{attendance}/review', [AdminApiController::class, 'reviewOvertime']);

            Route::get('/references/{type}', [AdminApiController::class, 'references']);
            Route::post('/references/{type}', [AdminApiController::class, 'storeReference']);
            Route::put('/references/{type}/{id}', [AdminApiController::class, 'updateReference']);
            Route::delete('/references/{type}/{id}', [AdminApiController::class, 'destroyReference']);
            Route::get('/options', [AdminApiController::class, 'options']);
            Route::get('/fx/pkr-usd', [AdminApiController::class, 'pkrUsdRate']);

            Route::get('/notifications', [AdminApiController::class, 'notifications']);
            Route::post('/notifications/read-all', [AdminApiController::class, 'markNotificationsRead']);
            Route::put('/account', [AdminApiController::class, 'updateAccount']);
            Route::put('/account/password', [AdminApiController::class, 'changePassword']);
        });

        Route::middleware('role:admin')->prefix('admin')->group(function () {
            Route::get('/hr-users', [AdminApiController::class, 'hrUsers']);
            Route::post('/hr-users', [AdminApiController::class, 'storeHrUser']);
            Route::put('/hr-users/{user}', [AdminApiController::class, 'updateHrUser']);
            Route::delete('/hr-users/{user}', [AdminApiController::class, 'destroyHrUser']);
            Route::get('/clients', [AdminApiController::class, 'clients']);
            Route::post('/clients', [AdminApiController::class, 'storeClient']);
            Route::get('/clients/{client}', [AdminApiController::class, 'client']);
            Route::put('/clients/{client}', [AdminApiController::class, 'updateClient']);
            Route::delete('/clients/{client}', [AdminApiController::class, 'destroyClient']);
            Route::get('/invoices', [AdminApiController::class, 'invoices']);
            Route::post('/invoices/generate', [AdminApiController::class, 'generateInvoice']);
            Route::get('/invoices/{invoice}', [AdminApiController::class, 'invoice']);
            Route::put('/invoices/{invoice}', [AdminApiController::class, 'updateInvoice']);
            Route::get('/company-finance', [AdminApiController::class, 'companyFinance']);
            Route::post('/company-finance/entries', [AdminApiController::class, 'storeCompanyFinanceEntry']);
            Route::delete('/company-finance/entries/{entry}', [AdminApiController::class, 'destroyCompanyFinanceEntry']);
        });

        Route::middleware('role:employee')->prefix('employee')->group(function () {
            // Profile endpoints remain available during first-login onboarding.
            Route::get('/profile', [EmployeeApiController::class, 'profile']);
            Route::post('/profile', [EmployeeApiController::class, 'updateProfile']);
            Route::delete('/profile/document', [EmployeeApiController::class, 'removeDocument']);

            Route::middleware('profile.complete')->group(function () {
                Route::get('/dashboard', [EmployeeApiController::class, 'dashboard']);
                Route::get('/schedules', [EmployeeApiController::class, 'schedules']);
                Route::get('/attendance', [EmployeeApiController::class, 'attendance']);
                Route::get('/change-requests', [EmployeeApiController::class, 'changeRequests']);
                Route::post('/change-requests', [EmployeeApiController::class, 'requestChange']);
                Route::post('/clock-in', [EmployeeApiController::class, 'clockIn']);
                Route::post('/clock-out', [EmployeeApiController::class, 'clockOut']);
                Route::post('/break/start', [EmployeeApiController::class, 'startBreak']);
                Route::post('/break/end', [EmployeeApiController::class, 'endBreak']);
                Route::put('/password', [EmployeeApiController::class, 'changePassword']);
            });
        });

        Route::middleware('role:client')->prefix('doctor')->group(function () {
            Route::get('/dashboard', [DoctorApiController::class, 'dashboard']);
            Route::get('/employees/{employee}/attendance', [DoctorApiController::class, 'employeeAttendance']);
            Route::get('/profile', [DoctorApiController::class, 'profile']);
            Route::post('/profile', [DoctorApiController::class, 'updateProfile']);
            Route::put('/password', [DoctorApiController::class, 'changePassword']);
        });
    });
});

/* PDF remains a server-rendered document; navigation inside the app stays SPA-only. */
Route::get('/invoices/{invoice}/pdf', InvoicePdfController::class)->middleware('auth')->name('invoices.pdf');

/* Existing signed onboarding / OAuth URLs are retained so old emails and integrations do not break. */
Route::get('/complete-profile', [CompleteProfileController::class, 'welcome'])->name('employee.completeProfile')->middleware('signed');
Route::get('/complete-profile/start/{employee}', [CompleteProfileController::class, 'start'])->name('employee.completeProfile.start');
Route::post('/employee/dashboard/profile/update', [CompleteProfileController::class, 'update'])->name('employee.profile.update');
Route::get('/profile-completed/{employee}', [CompleteProfileController::class, 'profile_completed'])->name('employee.completed');
Route::post('/auto-login/{employee}', [CompleteProfileController::class, 'autoLogin'])->name('employee.auto-login');
Route::get('/client/set-password', [ClientAuthController::class, 'showSetPasswordForm'])->name('client.password.form')->middleware('signed');
Route::post('/client/set-password', [ClientAuthController::class, 'setPassword'])->name('client.password.update');
Route::get('/google/connect', [OrgGoogleController::class, 'redirectToGoogle'])->middleware(['auth', 'role:employee'])->name('google.connect');
Route::get('/google/callback', [OrgGoogleController::class, 'handleGoogleCallback'])->name('google.callback');
Route::get('/auth/google/redirect', [OrgGoogleController::class, 'redirectToGoogleLogin'])->name('google.login');
Route::post('/google/disconnect', [OrgGoogleController::class, 'disconnectGoogle'])->middleware(['auth', 'role:employee'])->name('google.disconnect');

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
