<?php

use Awove\Core\Services\WorkReminders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->post('/work-reminders/{id}/read', function (Request $request, int $id, WorkReminders $reminders) {
    $reminders->markRead($request->user(), $id);

    return back();
})->whereNumber('id')->name('crm.work-reminders.read');
