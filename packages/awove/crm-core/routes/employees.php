<?php

use Awove\Core\Http\EmployeeReferenceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->prefix('people/employees')->name('crm.employees.')->group(function () {
    Route::get('/', [EmployeeReferenceController::class, 'index'])->name('index');
    Route::get('/export', [EmployeeReferenceController::class, 'export'])->name('export');
    Route::post('/', [EmployeeReferenceController::class, 'store'])->name('store');
    Route::get('/{id}', [EmployeeReferenceController::class, 'show'])->whereNumber('id')->name('show');
    Route::patch('/{id}', [EmployeeReferenceController::class, 'update'])->whereNumber('id')->name('update');
    Route::patch('/{id}/active', [EmployeeReferenceController::class, 'active'])->whereNumber('id')->name('active');
});
