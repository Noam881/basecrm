<?php

use Awove\Knowledge\KnowledgeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'crm.module:knowledge'])->prefix('knowledge')->name('knowledge.')->group(function () {
    Route::get('/', [KnowledgeController::class, 'index'])->name('index');
    Route::get('/export', [KnowledgeController::class, 'export'])->name('export');
    Route::get('/documents/{id}', [KnowledgeController::class, 'document'])->whereNumber('id')->name('documents.show');
    Route::post('/documents/{id}/archive', [KnowledgeController::class, 'archiveDocument'])->whereNumber('id')->name('documents.archive');
    Route::post('/renewals/{id}/renew', [KnowledgeController::class, 'renew'])->whereNumber('id')->name('renew');
    Route::get('/systems/{id}/login', [KnowledgeController::class, 'login'])->whereNumber('id')->name('systems.login');
    Route::post('/library/{id}/instructions', [KnowledgeController::class, 'instruction'])->whereNumber('id')->name('instructions.store');
    Route::post('/instructions/{id}/archive', [KnowledgeController::class, 'archiveInstruction'])->whereNumber('id')->name('instructions.archive');
    Route::get('/{section}/{id}', [KnowledgeController::class, 'show'])->whereIn('section', ['library', 'renewals', 'systems'])->whereNumber('id')->name('show');
    Route::post('/{section}', [KnowledgeController::class, 'store'])->whereIn('section', ['library', 'renewals', 'systems'])->name('store');
    Route::patch('/{section}/{id}', [KnowledgeController::class, 'update'])->whereIn('section', ['library', 'renewals', 'systems'])->whereNumber('id')->name('update');
    Route::post('/{section}/{id}/archive', [KnowledgeController::class, 'archive'])->whereIn('section', ['library', 'renewals', 'systems'])->whereNumber('id')->name('archive');
    Route::post('/{section}/{id}/documents', [KnowledgeController::class, 'addDocument'])->whereIn('section', ['library', 'renewals'])->whereNumber('id')->name('documents.store');
});
