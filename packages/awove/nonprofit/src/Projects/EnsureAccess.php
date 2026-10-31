<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Modules;
use Closure;
use Illuminate\Http\Request;

class EnsureAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(Modules::class)->enabled('nonprofit:projects'), 404);
        Access::actor($request->user());

        return $next($request);
    }
}
