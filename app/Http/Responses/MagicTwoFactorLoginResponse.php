<?php

namespace App\Http\Responses;

use App\Auth\MagicLogin;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse;

class MagicTwoFactorLoginResponse extends TwoFactorLoginResponse
{
    public function __construct(private MagicLogin $magicLogin, private StatefulGuard $guard) {}

    public function toResponse($request)
    {
        if (! $request->session()->has('auth.magic_pending')) {
            return parent::toResponse($request);
        }

        $pending = $request->session()->get('auth.magic_pending');
        $authenticated = $this->guard->user();
        $user = $authenticated instanceof User ? $authenticated->fresh() : null;

        // Fortify has already consumed login.id and completed the actual MFA login.
        if (! is_array($pending) ||
            ! is_int($pending['expires_at'] ?? null) ||
            $pending['expires_at'] <= now()->timestamp ||
            ! is_string($pending['credential_hash'] ?? null) ||
            ($pending['user_id'] ?? null) !== $user?->getKey() ||
            ! $user?->active ||
            ! $user->hasEnabledTwoFactorAuthentication() ||
            ! hash_equals($pending['credential_hash'], $this->magicLogin->fingerprint($user))) {
            $this->guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/magic-login')->withErrors([
                'email' => __(MagicLogin::INVALID_MESSAGE),
            ]);
        }

        $request->session()->passwordConfirmed();
        $request->session()->put('auth.method', 'magic_link');
        $request->session()->forget('auth.magic_pending');

        return parent::toResponse($request);
    }
}
