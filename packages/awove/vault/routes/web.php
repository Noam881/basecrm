<?php

use Awove\Vault\Http\AuthController;
use Awove\Vault\Http\NoStore;
use Awove\Vault\Http\PageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', NoStore::class, 'auth', 'verified', 'crm.module:vault'])->group(function () {
    Route::get('/vault', [PageController::class, 'index'])->name('vault.index');
    Route::get('/vault/confirm', [PageController::class, 'confirm'])->name('vault.confirm');
    Route::prefix('vault/api/auth')->group(function () {
        Route::get('/status', [AuthController::class, 'status']);
        Route::post('/enroll', [AuthController::class, 'enroll']);
        Route::post('/enroll/confirm', [AuthController::class, 'confirmEnrollment']);
        Route::post('/password', [AuthController::class, 'password']);
        Route::post('/email-start', [AuthController::class, 'emailStart']);
        Route::post('/resend', [AuthController::class, 'resend']);
        Route::post('/email', [AuthController::class, 'email']);
        Route::post('/factor', [AuthController::class, 'factor']);
        Route::post('/lock', [AuthController::class, 'lock']);
        Route::post('/approve', [AuthController::class, 'approve']);
        Route::post('/reset', [AuthController::class, 'reset']);
    });
});
