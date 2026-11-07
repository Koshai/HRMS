<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\EmployeeAdminController;
use App\Http\Controllers\EmployeeProfileController;
use App\Http\Controllers\LeaveAdminController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\OrganizationAdminController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ScheduleController;
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
    Route::get('schedule/mine', [ScheduleController::class, 'mine'])->name('schedule.mine');
    Route::get('payroll/mine', [PayrollController::class, 'mine'])->name('payroll.mine');
    Route::get('payroll/team-exceptions', [PayrollController::class, 'team'])->name('payroll.team');
    Route::post('payroll/team-exceptions/{employee}', [PayrollController::class, 'recommend'])->name('payroll.recommend');
    Route::get('payroll/payslips/{entry}', [PayrollController::class, 'payslip'])->name('payroll.payslip');
    Route::get('admin/payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::post('admin/payroll/policies', [PayrollController::class, 'policy'])->name('payroll.policy');
    Route::get('admin/payroll/employees/{employee}', [PayrollController::class, 'employeeCompensation'])->name('payroll.compensation');
    Route::post('admin/payroll/employees/{employee}', [PayrollController::class, 'compensation'])->name('payroll.compensation.store');
    Route::post('admin/payroll/runs', [PayrollController::class, 'createRun'])->name('payroll.runs.store');
    Route::get('admin/payroll/runs/{run}', [PayrollController::class, 'show'])->name('payroll.show');
    Route::post('admin/payroll/runs/{run}/reviews', [PayrollController::class, 'review'])->name('payroll.review');
    Route::post('admin/payroll/runs/{run}/adjustments', [PayrollController::class, 'adjustment'])->name('payroll.adjustment');
    Route::post('admin/payroll/runs/{run}/calculate', [PayrollController::class, 'calculate'])->name('payroll.calculate');
    Route::post('admin/payroll/runs/{run}/finalize', [PayrollController::class, 'finalize'])->name('payroll.finalize');
    Route::post('admin/payroll/runs/{run}/release', [PayrollController::class, 'release'])->name('payroll.release');
    Route::get('admin/payroll/runs/{run}/export', [PayrollController::class, 'export'])->name('payroll.export');
    Route::post('admin/payroll/entries/{entry}/payments', [PayrollController::class, 'payment'])->name('payroll.payment');
    Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule.index');
    Route::get('schedule/{employee}', [ScheduleController::class, 'show'])->name('schedule.show');
    Route::get('admin/schedule', [ScheduleController::class, 'settings'])->name('admin.schedule.index');
    Route::post('admin/schedule/templates', [ScheduleController::class, 'storeTemplate'])->name('admin.schedule.templates');
    Route::put('admin/schedule/templates/{template}', [ScheduleController::class, 'updateTemplate'])->name('admin.schedule.templates.update');
    Route::post('admin/schedule/assignments', [ScheduleController::class, 'assign'])->name('admin.schedule.assignments');
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
