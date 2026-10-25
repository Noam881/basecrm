<?php

use Awove\Hr\HrController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'crm.module:hr'])->prefix('hr')->name('hr.')->group(function () {
    Route::get('/', [HrController::class, 'index'])->name('index');
    Route::get('/export', [HrController::class, 'export'])->name('export');
    Route::get('/documents/{id}', [HrController::class, 'document'])->whereNumber('id')->name('documents.show');
    Route::post('/documents/{id}/archive', [HrController::class, 'archiveDocument'])->whereNumber('id')->name('documents.archive');
    Route::get('/checklist/{id}', [HrController::class, 'checklist'])->whereNumber('id')->name('checklist.show');
    Route::patch('/checklist/{id}', [HrController::class, 'updateChecklist'])->whereNumber('id')->name('checklist.update');
    Route::post('/cases/{id}/checklist', [HrController::class, 'storeChecklist'])->whereNumber('id')->name('checklist.store');
    Route::post('/cases/{id}/complete', [HrController::class, 'completeCase'])->whereNumber('id')->name('cases.complete');
    Route::get('/{section}/{id}', [HrController::class, 'show'])->whereIn('section', ['employees', 'vacancies', 'candidates', 'cases'])->whereNumber('id')->name('show');
    Route::post('/{section}', [HrController::class, 'store'])->whereIn('section', ['employees', 'vacancies', 'candidates', 'cases'])->name('store');
    Route::patch('/{section}/{id}', [HrController::class, 'update'])->whereIn('section', ['employees', 'vacancies', 'candidates', 'cases'])->whereNumber('id')->name('update');
    Route::post('/{section}/{id}/archive', [HrController::class, 'archive'])->whereIn('section', ['employees', 'vacancies', 'candidates', 'cases'])->whereNumber('id')->name('archive');
    Route::post('/{section}/{id}/documents', [HrController::class, 'addDocument'])->whereIn('section', ['employees', 'candidates', 'cases'])->whereNumber('id')->name('documents.store');
});
