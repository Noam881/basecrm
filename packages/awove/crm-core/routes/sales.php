<?php

use Awove\Core\Http\SalesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'crm.module:sales'])->group(function () {
    Route::post('/leads/{lead}/communications', [SalesController::class, 'communicate'])->name('sales.communications.store');
    Route::post('/leads/{lead}/quotations', [SalesController::class, 'quotation'])->name('sales.quotations.store');
    Route::patch('/leads/{lead}/quotations/{quotation}', [SalesController::class, 'updateQuotation'])->name('sales.quotations.update');
});
