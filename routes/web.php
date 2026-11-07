<?php

use App\Http\Controllers\EmployeeProfileController;
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
});

require __DIR__.'/settings.php';
