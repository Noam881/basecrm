<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MagicLogin
{
    public const LIFETIME_MINUTES = 10;

    public const INVALID_MESSAGE = 'This sign-in link is invalid or has expired. Request a new link.';

    /** @return array{token: string, url: string} */
    public function issue(User $user): array
    {
        $token = Str::random(64);
        $expires = now()->addMinutes(self::LIFETIME_MINUTES);
        DB::table('magic_login_tokens')->upsert([[
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'credential_hash' => $this->fingerprint($user),
            'expires_at' => $expires,
            'created_at' => now(),
        ]], ['user_id'], ['token_hash', 'credential_hash', 'expires_at', 'created_at']);

        // Build email links from the configured installation, never an incoming Host header.
        $urls = clone app(UrlGenerator::class);
        $urls->forceRootUrl(config('app.url'));
        $urls->forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME));

        return ['token' => $token, 'url' => $urls->temporarySignedRoute('magic-login.confirm', $expires, ['token' => $token])];
    }

    public function fingerprint(User $user): string
    {
        return hash_hmac('sha256', implode("\0", [
            config('core.installation'),
            config('app.url'),
            strtolower($user->email),
            'email-only-v1',
        ]), config('app.key'));
    }

    public function peek(string $token): ?User
    {
        $record = DB::table('magic_login_tokens')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        $user = $record ? User::find($record->user_id) : null;

        return $user && $user->active && hash_equals($record->credential_hash, $this->fingerprint($user)) ? $user : null;
    }

    public function consume(string $token): ?User
    {
        return DB::transaction(function () use ($token): ?User {
            $record = DB::table('magic_login_tokens')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $record) {
                return null;
            }
            $user = User::whereKey($record->user_id)->lockForUpdate()->first();
            if (! $user || ! $user->active || ! hash_equals($record->credential_hash, $this->fingerprint($user))) {
                return null;
            }
            DB::table('magic_login_tokens')->where('user_id', $user->id)->delete();
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                event(new Verified($user));
            }

            return $user;
        });
    }
}
