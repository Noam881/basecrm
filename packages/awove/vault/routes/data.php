<?php

use Awove\Vault\DataController;
use Awove\Vault\Http\NoStore;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', NoStore::class, 'auth', 'verified', 'crm.module:vault'])->prefix('vault/api')->name('vault.')->group(function () {
    Route::get('options', [DataController::class, 'options'])->name('options');
    Route::get('items', [DataController::class, 'listing'])->name('items.index');
    Route::get('items/{id}', [DataController::class, 'show'])->whereUuid('id')->name('items.show');
    Route::post('items', [DataController::class, 'store'])->name('items.store');
    Route::patch('items/{id}', [DataController::class, 'update'])->whereUuid('id')->name('items.update');
    Route::delete('items/{id}', [DataController::class, 'remove'])->whereUuid('id')->name('items.delete');
    Route::post('items/{id}/content', [DataController::class, 'content'])->whereUuid('id')->name('items.content');
    Route::post('items/{id}/files', [DataController::class, 'upload'])->whereUuid('id')->name('files.upload');
    Route::post('files/{id}/download', [DataController::class, 'download'])->whereUuid('id')->name('files.download');
    Route::delete('files/{id}', [DataController::class, 'removeFile'])->whereUuid('id')->name('files.delete');
});
