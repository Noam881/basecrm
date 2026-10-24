<?php

namespace Awove\Core\Http;

use Awove\Core\Modules;
use Closure;
use Illuminate\Http\Request;

class RequireModule
{
    public function handle(Request $request, Closure $next, string $module)
    {
        abort_unless(app(Modules::class)->enabled($module), 404);

        return $next($request);
    }
}
