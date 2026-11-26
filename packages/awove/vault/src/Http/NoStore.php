<?php

namespace Awove\Vault\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('vault/api/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return self::headers($next($request));
    }

    public static function headers(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->setVary('Cookie', false);

        return $response;
    }
}
