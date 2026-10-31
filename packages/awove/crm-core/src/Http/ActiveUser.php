<?php

namespace Awove\Core\Http;

class ActiveUser
{
    public function handle($request, \Closure $next)
    {
        if ($request->user() && ! $request->user()->fresh()?->active) {
            auth()->logout();
            $request->session()->invalidate();
            abort(403, __('This account is inactive.'));
        }

        return $next($request);
    }
}
