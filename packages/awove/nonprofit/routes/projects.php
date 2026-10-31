<?php

use Awove\Nonprofit\Projects\Controller;
use Awove\Nonprofit\Projects\EnsureAccess;
use Awove\Nonprofit\Projects\WorkController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', EnsureAccess::class])->prefix('nonprofit')->name('nonprofit.projects.')->group(function () {
    Route::get('/projects', [Controller::class, 'index'])->name('index');
    Route::get('/projects/export', [Controller::class, 'export'])->name('export');
    Route::post('/projects', [Controller::class, 'store'])->name('store');
    Route::get('/projects/{id}', [Controller::class, 'show'])->whereNumber('id')->name('show');
    Route::patch('/projects/{id}', [Controller::class, 'update'])->whereNumber('id')->name('update');
    Route::post('/projects/{id}/archive', [Controller::class, 'archive'])->whereNumber('id')->name('archive');
    Route::post('/projects/{id}/work/{kind}', [WorkController::class, 'store'])->whereNumber('id')->whereIn('kind', ['project-tasks', 'project-milestones'])->name('work.store');
    foreach (['project-tasks', 'project-milestones'] as $kind) {
        Route::get('/'.$kind.'/{id}', [WorkController::class, 'show'])->whereNumber('id')->defaults('kind', $kind);
        Route::patch('/'.$kind.'/{id}', [WorkController::class, 'update'])->whereNumber('id')->defaults('kind', $kind);
        Route::post('/'.$kind.'/{id}/archive', [WorkController::class, 'archive'])->whereNumber('id')->defaults('kind', $kind);
    }
    Route::post('/projects/{id}/meetings', [Controller::class, 'meeting'])->whereNumber('id');
    Route::patch('/projects/{id}/meetings/{meeting}', [Controller::class, 'meeting'])->whereNumber(['id', 'meeting']);
    Route::post('/projects/{id}/resources', [Controller::class, 'resource'])->whereNumber('id');
    Route::post('/projects/{id}/resources/{link}/archive', [Controller::class, 'unlinkResource'])->whereNumber(['id', 'link']);
    Route::post('/projects/{id}/payments', [Controller::class, 'payment'])->whereNumber('id');
    Route::post('/projects/{id}/payments/{link}/archive', [Controller::class, 'unlinkPayment'])->whereNumber(['id', 'link']);
    Route::post('/projects/{id}/documents', [Controller::class, 'document'])->whereNumber('id');
    Route::get('/project-documents/{id}', [Controller::class, 'openDocument'])->whereNumber('id');
    Route::post('/project-documents/{id}/archive', [Controller::class, 'archiveDocument'])->whereNumber('id');
});
