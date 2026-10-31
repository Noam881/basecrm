<?php

use Awove\Marketing\Controller;
use Awove\Marketing\EnsureMarketing;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', EnsureMarketing::class])->prefix('marketing')->group(function () {
    Route::get('/', [Controller::class, 'overview']);
    Route::get('/reports', [Controller::class, 'reports']);
    Route::get('/reports/export', [Controller::class, 'export'])->defaults('kind', 'reports');
    Route::get('/leads/{lead}/attribution', [Controller::class, 'attribution'])->whereNumber('lead');
    Route::patch('/leads/{lead}/attribution', [Controller::class, 'updateAttribution'])->whereNumber('lead');
    foreach (['campaigns', 'content'] as $kind) {
        foreach ([Route::get('/'.$kind, [Controller::class, 'index']), Route::post('/'.$kind, [Controller::class, 'store']), Route::get('/'.$kind.'/export', [Controller::class, 'export']), Route::get('/'.$kind.'/{id}', [Controller::class, 'show'])->whereNumber('id'), Route::patch('/'.$kind.'/{id}', [Controller::class, 'update'])->whereNumber('id'), Route::post('/'.$kind.'/{id}/archive', [Controller::class, 'archive'])->whereNumber('id'), Route::get('/'.$kind.'/{id}/document/{field}', [Controller::class, 'document'])->whereNumber('id')] as $route) {
            $route->defaults('kind', $kind);
        }
    }
    Route::post('/campaigns/{campaign}/reports', [Controller::class, 'storeReport'])->whereNumber('campaign');
    Route::patch('/campaigns/{campaign}/reports/{report}', [Controller::class, 'updateReport'])->whereNumber(['campaign', 'report']);
});
