<?php

use Awove\CustomerService\EnsureServiceAccess;
use Awove\CustomerService\ServiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', EnsureServiceAccess::class])->prefix('service')->name('customer-service.')->group(function () {
    Route::get('/', [ServiceController::class, 'index'])->name('index');
    Route::get('/export', [ServiceController::class, 'export'])->name('export');
    Route::post('/requests', [ServiceController::class, 'store'])->name('requests.store');
    Route::get('/requests/{id}', [ServiceController::class, 'show'])->whereNumber('id')->name('requests.show');
    Route::patch('/requests/{id}', [ServiceController::class, 'update'])->whereNumber('id')->name('requests.update');
    Route::post('/requests/{id}/interactions', [ServiceController::class, 'interaction'])->whereNumber('id')->name('requests.interactions');
    Route::post('/requests/{id}/archive', [ServiceController::class, 'archive'])->whereNumber('id')->name('requests.archive');
    Route::get('/requests/{id}/document', [ServiceController::class, 'document'])->whereNumber('id')->name('requests.document');
    Route::get('/requests/{id}/history/{historyId}/document', [ServiceController::class, 'historicalDocument'])->whereNumber(['id', 'historyId'])->name('requests.history.document');
    Route::post('/feedback', [ServiceController::class, 'storeFeedback'])->name('feedback.store');
    Route::get('/feedback/{id}', [ServiceController::class, 'showFeedback'])->whereNumber('id')->name('feedback.show');
    Route::patch('/feedback/{id}', [ServiceController::class, 'updateFeedback'])->whereNumber('id')->name('feedback.update');
    Route::post('/feedback/{id}/archive', [ServiceController::class, 'archiveFeedback'])->whereNumber('id')->name('feedback.archive');
});
