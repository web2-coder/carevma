<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EmployeeProfile\ProfileController;
use App\Http\Controllers\EmployeeProfile\AttendanceController;
use App\Http\Controllers\EmployeeProfile\CompleteProfileController;
use App\Http\Controllers\EmployeeController;


// Route::get('/complete-profile', [AuthController::class, 'showCompleteProfileForm'])
//     ->name('employee.completeProfile')
//     ->middleware('signed');

Route::middleware(['auth', 'employee' ,'profile.complete'])->prefix('employee')->group(function () {
    Route::get('/dashboard', [ProfileController::class, 'index'])->name('employee.dashboard');

    Route::post('/clock-in', [AttendanceController::class, 'clockIn'])->name('attendance.clockIn');
    Route::post('/clock-out', [AttendanceController::class, 'clockOut'])->name('attendance.clockOut');   
    Route::post('/attendance/start-break', [AttendanceController::class, 'startBreak'])->name('attendance.startBreak');
    Route::post('/attendance/end-break', [AttendanceController::class, 'endBreak'])->name('attendance.endBreak');

    Route::get('/my-attendance',[ProfileController::class,'employee_attendance'])->name('my.attendance'); 
    
    Route::post('/attendance/request-change', [AttendanceController::class, 'requestChange'])
    ->name('attendance.requestChange');

    Route::get('settings',[ProfileController::class,'settings'])->name('employee.setting');

    Route::put('change-password', [ProfileController::class, 'change_password'])
    ->name('employees.change-password');

    Route::get('my-schedule',[ProfileController::class,'my_schedule'])->name('employee.schedule');
   
});

Route::prefix('employee/dashboard/profile')->name('employee.profile.')->group(function () {
    Route::get('/edit', [ProfileController::class, 'editProfile'])->name('edit');
    Route::post('/update', [ProfileController::class, 'updateProfile'])->name('update');
    Route::post('/delete-doc', [ProfileController::class, 'delete_document'])->name('delete.document');
});

// 1️⃣ Employee clicks email link → Welcome screen (SIGNED)
Route::get('/complete-profile', [CompleteProfileController::class, 'welcome'])
    ->name('employee.completeProfile')
    ->middleware('signed');

// 2️⃣ Show full profile form (GET — MUST be GET)
Route::get('/complete-profile/start/{employee}', [CompleteProfileController::class, 'start'])
    ->name('employee.completeProfile.start');

// 3️⃣ Save completed profile (POST)
// Route::post('/complete-profile/update', [CompleteProfileController::class, 'update'])
//     ->name('employee.profile.update');
Route::post('/employee/dashboard/profile/update', [CompleteProfileController::class, 'update'])
    ->name('employee.profile.update');

// 4️⃣ Success page
Route::get('/profile-completed/{employee}', [CompleteProfileController::class, 'profile_completed'])
    ->name('employee.completed');

Route::post('auto-login/{employee}', [CompleteProfileController::class, 'autoLogin'])->name('employee.auto-login');