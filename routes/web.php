<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\EmployeeAdminController;
use App\Http\Controllers\EmployeeProfileController;
use App\Http\Controllers\LeaveAdminController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\OrganizationAdminController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::view('/', 'landing')->name('home');

Route::get('guide', function () {
    return view('guide', [
        'guide' => Str::markdown(File::get(base_path('docs/user-guide/portal.md')), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]),
    ]);
})->middleware('auth')->name('guide');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::get('employees', [EmployeeProfileController::class, 'index'])->name('employees.index');
    Route::get('employees/{employee}', [EmployeeProfileController::class, 'show'])->name('employees.show');
    Route::get('attendance/mine', [AttendanceController::class, 'mine'])->name('attendance.mine');
    Route::post('attendance/punch', [AttendanceController::class, 'punch'])->name('attendance.punch');
    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/{employee}', [AttendanceController::class, 'show'])->name('attendance.show');
    Route::post('attendance/{employee}/manual', [AttendanceController::class, 'manual'])->name('attendance.manual');
    Route::post('attendance/{employee}/records/{record}/corrections', [AttendanceController::class, 'correct'])->name('attendance.correct');
    Route::post('attendance/{employee}/corrections/{correction}/decision', [AttendanceController::class, 'decide'])->name('attendance.decide');
    Route::get('leave/mine', [LeaveController::class, 'mine'])->name('leave.mine');
    Route::post('leave/requests', [LeaveController::class, 'store'])->name('leave.store');
    Route::post('leave/requests/{leave}/withdraw', [LeaveController::class, 'withdraw'])->name('leave.withdraw');
    Route::get('leave', [LeaveController::class, 'index'])->name('leave.index');
    Route::post('leave/requests/{leave}/decision', [LeaveController::class, 'decide'])->name('leave.decide');
    Route::post('leave/requests/{leave}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel');
    Route::get('admin/leave', [LeaveAdminController::class, 'index'])->name('admin.leave.index');
    Route::post('admin/leave/types', [LeaveAdminController::class, 'type'])->name('admin.leave.types');
    Route::put('admin/leave/types/{type}', [LeaveAdminController::class, 'updateType'])->name('admin.leave.types.update');
    Route::post('admin/leave/policies', [LeaveAdminController::class, 'policy'])->name('admin.leave.policies');
    Route::post('admin/leave/holidays', [LeaveAdminController::class, 'holiday'])->name('admin.leave.holidays');
    Route::post('admin/leave/grants', [LeaveAdminController::class, 'grant'])->name('admin.leave.grants');
    Route::get('admin/employees/create', [EmployeeAdminController::class, 'create'])->name('admin.employees.create');
    Route::post('admin/employees', [EmployeeAdminController::class, 'store'])->name('admin.employees.store');
    Route::get('admin/employees/{employee}/edit', [EmployeeAdminController::class, 'edit'])->name('admin.employees.edit');
    Route::put('admin/employees/{employee}', [EmployeeAdminController::class, 'update'])->name('admin.employees.update');
    Route::get('admin/employees/{employee}/assignment', [EmployeeAdminController::class, 'assignment'])->name('admin.employees.assignment');
    Route::post('admin/employees/{employee}/assignment', [EmployeeAdminController::class, 'assign'])->name('admin.employees.assign');
    Route::get('admin/employees/{employee}/assignment/{assignment}/edit', [EmployeeAdminController::class, 'editAssignment'])->name('admin.employees.assignment.edit');
    Route::put('admin/employees/{employee}/assignment/{assignment}', [EmployeeAdminController::class, 'updateAssignment'])->name('admin.employees.assignment.update');
    Route::post('admin/employees/{employee}/provision', [EmployeeAdminController::class, 'provision'])->name('admin.employees.provision');
    Route::post('admin/employees/{employee}/resend-invite', [EmployeeAdminController::class, 'resendInvite'])->name('admin.employees.resend-invite');
    Route::get('admin/organization/{type}', [OrganizationAdminController::class, 'index'])->name('admin.organization.index');
    Route::post('admin/organization/{type}', [OrganizationAdminController::class, 'store'])->name('admin.organization.store');
    Route::put('admin/organization/{type}/{item}', [OrganizationAdminController::class, 'update'])->name('admin.organization.update');
});

require __DIR__.'/settings.php';
