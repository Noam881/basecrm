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
        $user = Access::actor($request->user());

        return Inertia::render('Vault/Index', ['table' => $records->listing($request), ...$records->options($user),
            'monday_import' => $user->hasRole('Administrator') && $user->can('imports.manage') && $user->can('vault.admin')
                && is_string(config('vault.monday_plan_file')) && is_string(config('vault.monday_plan_sha256')) && is_string(config('vault.monday_token_file')),
            'monday_workdocs' => $user->hasRole('Administrator') && $user->can('imports.manage') && $user->can('vault.admin')
                && is_string(config('vault.monday_workdoc_plan_file')) && is_string(config('vault.monday_workdoc_plan_sha256'))]);
    }

    public function confirm(Request $request)
    {
        Access::actor($request->user());

        // The email token lives in a fragment and is never supplied to GET/HEAD.
        return Inertia::render('Vault/Confirm');
    }
}
