<?php

namespace App\Http\Middleware;

use App\Support\Localization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetInstallationLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        Localization::apply();

        return $next($request);
    }
}
