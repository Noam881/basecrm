<?php

namespace Awove\Operations;

use Closure;
use Illuminate\Http\Request;

class EnsureOperationsAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(Access::available($request->user()), 403);

        return $next($request);
    }
}
