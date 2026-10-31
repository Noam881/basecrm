<?php

namespace Awove\CustomerService;

use Closure;
use Illuminate\Http\Request;

class EnsureServiceAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(Access::available($request->user()), 403);

        return $next($request);
    }
}
