<?php

use Awove\Nonprofit\Fundraising\Controller;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'crm.module:nonprofit:fundraising'])->prefix('nonprofit/fundraising')->name('nonprofit.fundraising.')->group(function () {
    Route::get('/', [Controller::class, 'index'])->name('index');
    Route::get('/export', [Controller::class, 'export'])->name('export');
    Route::post('/classifications', [Controller::class, 'classify']);
    Route::post('/constituents', [Controller::class, 'store']);
    Route::get('/constituents/{id}', [Controller::class, 'show'])->whereNumber('id');
    Route::patch('/constituents/{id}', [Controller::class, 'update'])->whereNumber('id');
    Route::post('/constituents/{id}/archive', [Controller::class, 'archive'])->whereNumber('id');
    Route::post('/constituents/{id}/pledges', [Controller::class, 'storePledge'])->whereNumber('id');
    Route::get('/pledges/{id}', [Controller::class, 'showPledge'])->whereNumber('id');
    Route::patch('/pledges/{id}', [Controller::class, 'updatePledge'])->whereNumber('id');
    Route::post('/pledges/{id}/archive', [Controller::class, 'archivePledge'])->whereNumber('id');
    Route::post('/pledges/{id}/receipts', [Controller::class, 'link'])->whereNumber('id');
    Route::post('/receipts/{id}/archive', [Controller::class, 'unlink'])->whereNumber('id');
});
