<?php

use Awove\Core\Http\SpreadsheetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->prefix('spreadsheets')->name('crm.spreadsheets.')->group(function () {
    Route::get('/', [SpreadsheetController::class, 'index'])->name('index');
    Route::post('/preview', [SpreadsheetController::class, 'preview'])->middleware('throttle:20,1')->name('preview');
    Route::post('/confirm', [SpreadsheetController::class, 'confirm'])->middleware('throttle:10,1')->name('confirm');
    Route::get('/export/{type}', [SpreadsheetController::class, 'export'])->name('export');
    Route::get('/template/{type}', [SpreadsheetController::class, 'template'])->name('template');
});
