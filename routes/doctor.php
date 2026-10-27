<?php

use App\Http\Controllers\Admin\Client\AdminClientController;
use App\Http\Controllers\Admin\Client\ClientAuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Doctor\DoctorController;


Route::get('/client/set-password', [ClientAuthController::class, 'showSetPasswordForm'])
    ->name('client.password.form')
    ->middleware('signed');

Route::post('/client/set-password', [ClientAuthController::class, 'setPassword'])
    ->name('client.password.update');
    
Route::prefix('doctor')->middleware('auth')->group(function () {

    // Dashboard
    Route::get('/dashboard', [DoctorController::class, 'dashboard'])
        ->name('doctor.dashboard');

    // Employee Attendance Page
    Route::get('/employee/{employee}/attendance', [DoctorController::class, 'employeeAttendance'])
        ->name('doctor.employee.attendance')
        ->where('employee', '[0-9]+'); // ensures employee ID is numeric

    // Optional: Attendance Data JSON (for DataTables)
    Route::get('/employee/{employee}/attendance/data', [DoctorController::class, 'attendanceData'])
        ->name('doctor.employee.attendance.data')
        ->where('employee', '[0-9]+');


    Route::get('/profile', [DoctorController::class, 'profile'])->name('doctor.profile');
    Route::post('/profile/update', [DoctorController::class, 'profileUpdate'])->name('doctor.profile.update');
    Route::post('/profile/change-password', [ClientAuthController::class, 'changePassword'])->name('doctor.profile.changePassword');

});