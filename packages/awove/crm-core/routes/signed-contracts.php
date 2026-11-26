<?php

use Awove\Core\Http\SignedContractController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'crm.module:sales'])->group(function () {
    Route::get('/leads/{lead}/signed-contracts', [SignedContractController::class, 'index'])->whereNumber('lead')->name('crm.signed-contracts.index');
    Route::post('/leads/{lead}/signed-contracts', [SignedContractController::class, 'store'])->whereNumber('lead')->name('crm.signed-contracts.store');
    Route::get('/leads/{lead}/signed-contracts/{signedContract}/download', [SignedContractController::class, 'download'])->whereNumber(['lead', 'signedContract'])->name('crm.signed-contracts.download');
});
