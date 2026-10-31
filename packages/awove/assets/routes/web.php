<?php

use Awove\Assets\AssetsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'crm.module:assets'])->prefix('assets')->name('assets.')->group(function () {
    Route::post('/classifications', [AssetsController::class, 'classify'])->name('classifications.store');
    Route::get('/', [AssetsController::class, 'index'])->name('index');
    Route::get('/export', [AssetsController::class, 'export'])->name('export');
    Route::get('/register/{id}', [AssetsController::class, 'show'])->whereNumber('id')->name('show');
    Route::post('/register', [AssetsController::class, 'store'])->name('store');
    Route::patch('/register/{id}', [AssetsController::class, 'update'])->whereNumber('id')->name('update');
    Route::post('/register/{id}/archive', [AssetsController::class, 'archive'])->whereNumber('id')->name('archive');
    Route::post('/register/{id}/assignments', [AssetsController::class, 'assign'])->whereNumber('id')->name('assign');
    Route::patch('/assignments/{id}', [AssetsController::class, 'updateAssignment'])->whereNumber('id')->name('assignments.update');
    Route::post('/assignments/{id}/return', [AssetsController::class, 'returnAssignment'])->whereNumber('id')->name('return');
    Route::post('/register/{id}/maintenance', [AssetsController::class, 'storeMaintenance'])->whereNumber('id')->name('maintenance.store');
    Route::get('/maintenance/{id}', [AssetsController::class, 'showMaintenance'])->whereNumber('id')->name('maintenance.show');
    Route::patch('/maintenance/{id}', [AssetsController::class, 'updateMaintenance'])->whereNumber('id')->name('maintenance.update');
    Route::post('/maintenance/{id}/complete', [AssetsController::class, 'completeMaintenance'])->whereNumber('id')->name('maintenance.complete');
    Route::post('/maintenance/{id}/cost', [AssetsController::class, 'recordCost'])->whereNumber('id')->name('maintenance.cost');
    Route::post('/maintenance/{id}/archive', [AssetsController::class, 'archiveMaintenance'])->whereNumber('id')->name('maintenance.archive');
    Route::post('/register/{id}/documents', [AssetsController::class, 'addDocument'])->whereNumber('id')->name('documents.store');
    Route::get('/documents/{id}', [AssetsController::class, 'document'])->whereNumber('id')->name('documents.show');
    Route::post('/documents/{id}/archive', [AssetsController::class, 'archiveDocument'])->whereNumber('id')->name('documents.archive');
});
