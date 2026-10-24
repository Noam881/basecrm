<?php

use Awove\Core\Http\ProposalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'crm.module:sales'])->group(function () {
    Route::get('/leads/{lead}/proposals', [ProposalController::class, 'index'])->whereNumber('lead')->name('crm.proposals.index');
    Route::post('/leads/{lead}/proposals', [ProposalController::class, 'store'])->whereNumber('lead')->name('crm.proposals.store');
    Route::get('/leads/{lead}/proposals/{proposal}/download', [ProposalController::class, 'download'])->whereNumber(['lead', 'proposal'])->name('crm.proposals.download');
});
