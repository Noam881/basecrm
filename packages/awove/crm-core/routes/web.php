<?php

use Awove\Core\Http\CrmController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    Route::get('/dashboard', [CrmController::class, 'dashboard'])->name('dashboard');
    Route::get('/leads', [CrmController::class, 'index'])->name('crm.leads.index');
    Route::post('/leads', [CrmController::class, 'store'])->name('crm.leads.store');
    Route::get('/leads/{lead}', [CrmController::class, 'show'])->name('crm.leads.show');
    Route::patch('/leads/{lead}', [CrmController::class, 'update'])->name('crm.leads.update');
    Route::post('/leads/{lead}/archive', [CrmController::class, 'archive'])->name('crm.leads.archive');
    Route::post('/leads/{lead}/notes', [CrmController::class, 'note'])->name('crm.notes.store');
    Route::post('/leads/{lead}/tasks', [CrmController::class, 'task'])->name('crm.tasks.store');
    Route::patch('/tasks/{task}', [CrmController::class, 'updateTask'])->name('crm.tasks.update');
    Route::get('/tasks', [CrmController::class, 'tasks'])->name('crm.tasks.index');
    Route::get('/people', [CrmController::class, 'people'])->name('crm.people.index');
    Route::post('/people/{type}', [CrmController::class, 'identity'])->whereIn('type', ['contacts', 'companies'])->name('crm.people.store');
    Route::get('/people/{type}/{id}', [CrmController::class, 'showIdentity'])->whereIn('type', ['contacts', 'companies'])->whereNumber('id')->name('crm.people.show');
    Route::patch('/people/{type}/{id}', [CrmController::class, 'updateIdentity'])->whereIn('type', ['contacts', 'companies'])->whereNumber('id')->name('crm.people.update');
    Route::get('/reminders', [CrmController::class, 'reminders'])->name('crm.reminders.index');
    Route::post('/reminders/{reminder}/read', [CrmController::class, 'read'])->name('crm.reminders.read');
    Route::get('/business-settings', [CrmController::class, 'settings'])->name('crm.settings');
    Route::patch('/business-settings', [CrmController::class, 'saveSettings'])->name('crm.settings.update');
    Route::post('/statuses', [CrmController::class, 'status'])->name('crm.statuses.update');
    Route::post('/preferences', [CrmController::class, 'preferences'])->name('crm.preferences');
    Route::post('/users', [CrmController::class, 'user'])->name('crm.users.store');
    Route::patch('/users/{id}', [CrmController::class, 'deactivate'])->name('crm.users.update');
});
