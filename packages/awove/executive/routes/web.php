<?php

use Awove\Executive\ExecutiveController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'crm.module:executive'])->prefix('executive')->name('executive.')->group(function () {
    Route::get('/', [ExecutiveController::class, 'index'])->name('index');
    Route::get('/export', [ExecutiveController::class, 'export'])->name('export');
    foreach (['goals' => 'Goal', 'tasks' => 'Task', 'routines' => 'Routine'] as $path => $method) {
        Route::post('/'.$path, [ExecutiveController::class, 'store'.$method])->name($path.'.store');
        Route::get('/'.$path.'/{id}', [ExecutiveController::class, lcfirst($method)])->whereNumber('id')->name($path.'.show');
        Route::patch('/'.$path.'/{id}', [ExecutiveController::class, 'update'.$method])->whereNumber('id')->name($path.'.update');
        Route::post('/'.$path.'/{id}/archive', [ExecutiveController::class, 'archive'.$method])->whereNumber('id')->name($path.'.archive');
    }
    Route::post('/routines/{id}/complete', [ExecutiveController::class, 'completeRoutine'])->whereNumber('id')->name('routines.complete');
});
