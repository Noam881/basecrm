<?php

use Awove\Core\Http\DocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    Route::get('/people/{type}/{id}/documents', [DocumentController::class, 'index'])->whereIn('type', ['contacts', 'companies'])->whereNumber('id')->name('crm.documents.index');
    Route::post('/people/{type}/{id}/documents', [DocumentController::class, 'store'])->whereIn('type', ['contacts', 'companies'])->whereNumber('id')->name('crm.documents.store');
    Route::get('/people/{type}/{id}/documents/{document}/download', [DocumentController::class, 'download'])->whereIn('type', ['contacts', 'companies'])->whereNumber(['id', 'document'])->name('crm.documents.download');
});
