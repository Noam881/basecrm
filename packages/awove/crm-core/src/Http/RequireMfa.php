<?php

namespace Awove\Core\Http;

class RequireMfa
{
    public function handle($request, \Closure $next)
    {
        if (($request->user()->can('users.manage') || $request->user()->can('settings.manage')) && ! $request->user()->two_factor_confirmed_at) {
            return redirect('/settings/security')->with('status', 'Enroll in two-factor authentication before using administrator features.');
        }

        return $next($request);
    }
}
