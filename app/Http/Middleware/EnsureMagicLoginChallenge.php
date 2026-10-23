<?php

namespace App\Http\Middleware;

use App\Auth\MagicLogin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMagicLoginChallenge
{
    public function __construct(private MagicLogin $magicLogin) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('two-factor.login', 'two-factor.login.store') ||
            ! $request->session()->has('auth.magic_pending')) {
            return $next($request);
        }

        $pending = $request->session()->get('auth.magic_pending');
        $user = is_array($pending) && is_int($pending['user_id'] ?? null)
            ? User::find($pending['user_id'])
            : null;

        if (! is_array($pending) ||
            ! is_int($pending['expires_at'] ?? null) ||
            $pending['expires_at'] <= now()->timestamp ||
            ! is_string($pending['credential_hash'] ?? null) ||
            ($pending['user_id'] ?? null) !== $request->session()->get('login.id') ||
            ! $user?->active ||
            ! $user->hasEnabledTwoFactorAuthentication() ||
            ! hash_equals($pending['credential_hash'], $this->magicLogin->fingerprint($user))) {
            $request->session()->forget([
                'login.id',
                'login.remember',
                'auth.magic_pending',
                'auth.password_confirmed_at',
                'auth.method',
            ]);

            return redirect('/magic-login')->withErrors([
                'email' => __(MagicLogin::INVALID_MESSAGE),
            ]);
        }

        return $next($request);
    }
}
