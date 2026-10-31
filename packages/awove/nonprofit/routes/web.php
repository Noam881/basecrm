<?php

use Awove\Nonprofit\NonprofitModule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'crm.module:nonprofit'])->get('/nonprofit', function (Request $request) {
    $sections = (new NonprofitModule)->sections($request->user());
    abort_unless($sections, 403);

    return redirect($sections[0]['href']);
});
