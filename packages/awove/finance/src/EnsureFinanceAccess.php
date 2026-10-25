<?php

namespace Awove\Finance;

use Closure;
use Illuminate\Http\Request;

class EnsureFinanceAccess
{
    public function handle(Request $request, Closure $next, ?string $cap = null)
    {
        abort_unless($cap ? Access::available($request->user(), $cap) : app(FinanceModule::class)->sections($request->user()) !== [], 403);

        return $next($request);
    }
}
