<?php

use Awove\Nonprofit\Programs\Access;
use Awove\Nonprofit\Programs\Controller;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'crm.module:nonprofit:programs'])->prefix('nonprofit/programs')->name('nonprofit.programs.')->group(function () {
    Route::get('/', [Controller::class, 'index'])->name('index');
    Route::get('/export', [Controller::class, 'export'])->name('export');
    Route::get('/documents/{id}', [Controller::class, 'document'])->whereNumber('id')->name('documents.show');
    Route::post('/documents/{id}/archive', [Controller::class, 'archiveDocument'])->whereNumber('id')->name('documents.archive');
    Route::post('/programs/{program}/documents', [Controller::class, 'addDocument'])->whereNumber('program')->name('documents.store');
    Route::post('/programs/{program}/{kind}', [Controller::class, 'child'])->whereNumber('program')->whereIn('kind', ['sessions', 'feedback'])->name('children.store');
    Route::post('/sessions/{session}/attendance', [Controller::class, 'attend'])->whereNumber('session')->name('attendance.store');
    Route::get('/{kind}/{id}', [Controller::class, 'show'])->whereNumber('id')->whereIn('kind', array_keys(Access::MODELS))->name('show');
    Route::post('/{kind}', [Controller::class, 'store'])->whereIn('kind', ['programs', 'participants', 'locations', 'groups'])->name('store');
    Route::patch('/{kind}/{id}', [Controller::class, 'update'])->whereNumber('id')->whereIn('kind', array_keys(Access::MODELS))->name('update');
    Route::post('/{kind}/{id}/archive', [Controller::class, 'archive'])->whereNumber('id')->whereIn('kind', ['programs', 'participants', 'locations', 'groups', 'sessions', 'feedback'])->name('archive');
});
