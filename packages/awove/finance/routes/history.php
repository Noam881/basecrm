<?php

use Awove\Finance\EnsureFinanceAccess;
use Awove\Finance\History\Controller;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', EnsureFinanceAccess::class.':history'])->prefix('finance/history')->group(function () {
    Route::get('/', [Controller::class, 'index']);
    Route::get('/identities/{type}/{identity}', [Controller::class, 'identity'])->whereIn('type', ['contacts', 'companies'])->whereNumber('identity');
    Route::get('/{id}', [Controller::class, 'show'])->whereNumber('id');
    Route::get('/{id}/entries', [Controller::class, 'entries'])->whereNumber('id');
    Route::get('/{id}/entries/{entry}', [Controller::class, 'entry'])->whereNumber(['id', 'entry']);
});
