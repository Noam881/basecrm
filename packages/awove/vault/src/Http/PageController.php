<?php

namespace Awove\Vault\Http;

use Awove\Vault\Access;
use Awove\Vault\Records;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PageController
{
    public function index(Request $request, Records $records)
    {
        return Inertia::render('Vault/Index', ['table' => $records->listing($request), ...$records->options($request->user())]);
    }

    public function confirm(Request $request)
    {
        Access::actor($request->user());

        // The email token lives in a fragment and is never supplied to GET/HEAD.
        return Inertia::render('Vault/Confirm');
    }
}
