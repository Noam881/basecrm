<?php

use Awove\Finance\EnsureFinanceAccess;
use Awove\Finance\Payroll\Controller;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', EnsureFinanceAccess::class.':payroll'])->prefix('finance/payroll')->group(function () {
    Route::get('/', [Controller::class, 'index']);
    Route::post('/', [Controller::class, 'store']);
    Route::get('/export', [Controller::class, 'export']);
    Route::get('/{id}', [Controller::class, 'show'])->whereNumber('id');
    Route::patch('/{id}', [Controller::class, 'update'])->whereNumber('id');
    Route::post('/{id}/archive', [Controller::class, 'archive'])->whereNumber('id');
    Route::post('/{id}/documents', [Controller::class, 'addDocument'])->whereNumber('id');
    Route::get('/{id}/documents/{document}', [Controller::class, 'document'])->whereNumber(['id', 'document']);
});
