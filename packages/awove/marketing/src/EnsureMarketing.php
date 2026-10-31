<?php

namespace Awove\Marketing;

use Closure;

class EnsureMarketing
{
    public function handle($request, Closure $next)
    {
        Access::actor($request->user());

        return $next($request);
    }
}
