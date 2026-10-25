<?php

use Awove\Finance\EnsureFinanceAccess;
use Awove\Finance\LedgerController;
use Awove\Finance\OverviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', EnsureFinanceAccess::class])->prefix('finance')->group(function () {
    Route::get('/', OverviewController::class);
    foreach (['collections', 'expenses'] as $cap) {
        Route::middleware(EnsureFinanceAccess::class.':'.$cap)->prefix($cap)->group(function () use ($cap) {
            $routes = [Route::get('/', [LedgerController::class, 'index']), Route::post('/', [LedgerController::class, 'store']), Route::get('/export', [LedgerController::class, 'export']), Route::post('/classifications', [LedgerController::class, 'classify']), Route::get('/{id}', [LedgerController::class, 'show'])->whereNumber('id'), Route::patch('/{id}', [LedgerController::class, 'update'])->whereNumber('id'), Route::post('/{id}/payments', [LedgerController::class, 'payment'])->whereNumber('id'), Route::post('/{id}/cancel', [LedgerController::class, 'cancel'])->whereNumber('id'), Route::get('/{id}/document', [LedgerController::class, 'document'])->whereNumber('id')];
            foreach ($routes as $route) {
                $route->defaults('cap', $cap);
            }
        });
    }
});
