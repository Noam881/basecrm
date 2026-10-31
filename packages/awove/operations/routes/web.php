<?php

use Awove\Operations\DirectoryController;
use Awove\Operations\EnsureOperationsAccess;
use Awove\Operations\OrderController;
use Awove\Operations\OverviewController;
use Awove\Operations\RequestController;
use Awove\Operations\RoutineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', EnsureOperationsAccess::class])->prefix('operations')->name('operations.')->group(function () {
    Route::get('/', OverviewController::class)->name('overview');
    foreach (['requests' => RequestController::class, 'orders' => OrderController::class, 'routines' => RoutineController::class, 'directory' => DirectoryController::class] as $path => $controller) {
        Route::get('/'.$path, [$controller, 'index'])->name($path.'.index');
        Route::get('/'.$path.'/export', [$controller, 'export'])->name($path.'.export');
        Route::post('/'.$path, [$controller, 'store'])->name($path.'.store');
        Route::get('/'.$path.'/{id}', [$controller, 'show'])->whereNumber('id')->name($path.'.show');
        Route::patch('/'.$path.'/{id}', [$controller, 'update'])->whereNumber('id')->name($path.'.update');
        Route::post('/'.$path.'/{id}/archive', [$controller, 'archive'])->whereNumber('id')->name($path.'.archive');
    }
    Route::get('/requests/{id}/document', [RequestController::class, 'document'])->whereNumber('id')->name('requests.document');
    Route::get('/orders/{id}/document', [OrderController::class, 'document'])->whereNumber('id')->name('orders.document');
    Route::post('/orders/{id}/receipts', [OrderController::class, 'receipt'])->whereNumber('id')->name('orders.receipts');
    Route::post('/routines/{id}/complete', [RoutineController::class, 'complete'])->whereNumber('id')->name('routines.complete');
});
