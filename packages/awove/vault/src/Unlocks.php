<?php

namespace Awove\Vault;

use App\Models\User;
use Awove\Vault\Models\Challenge;
use Awove\Vault\Models\Member;
use Awove\Vault\Models\RecoveryCode;
use Awove\Vault\Models\UnlockGrant;
use Awove\Vault\Notifications\EmailChallenge;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\CloudflareTransport;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Mail\Transport\SesV2Transport;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class Unlocks
{
    public const LIFETIME_SECONDS = 300;

    public const CHALLENGE_SECONDS = 600;

    public function actor(Request $request): User
    {
        $user = Access::actor($request->user());
        abort_unless($user->hasVerifiedEmail() && $user->getAllPermissions()->contains(fn ($permission) => str_starts_with($permission->name, 'vault.')), 403);

        return $user;
    }

    public static function throttle(User $user, string $action, int $attempts = 5, int $seconds = 60): void
    {
        $key = 'vault:'.hash('sha256', Enrollment::installation().':'.$user->id.':'.$action);
        abort_if(RateLimiter::tooManyAttempts($key, $attempts), 429, __('Too many vault attempts. Wait before trying again.'));
        RateLimiter::hit($key, $seconds);
    }

    public static function sessionHash(Request $request): string
    {
        abort_unless($request->hasSession() && $request->session()->getId() !== '', 403);

        return hash_hmac('sha256', Enrollment::installation()."\0".$request->session()->getId(), config('app.key'));
    }

    public static function fingerprint(User $user, Member $member): string
    {
        $permissions = $user->getAllPermissions()->pluck('name')->filter(fn ($permission) => str_starts_with($permission, 'vault.'))->sort()->values()->all();

        return hash_hmac('sha256', json_encode([AuthMode::current(), strtolower($user->email), $user->email_verified_at?->toISOString(), $permissions,
            $member->factor_generation, AuthMode::riddle() ? app(Riddle::class)->fingerprint() : $member->password_hash], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function grants(Request $request, User $user)
    {
        return UnlockGrant::where('installation_id', Enrollment::installation())->where('user_id', $user->id)->where('session_hash', self::sessionHash($request));
    }

    private function challenges(Request $request, User $user)
    {
        return Challenge::where('installation_id', Enrollment::installation())->where('user_id', $user->id)->where('session_hash', self::sessionHash($request));
    }

    private function valid(Member $member, User $user, Challenge|UnlockGrant $record): bool
    {
        return (AuthMode::riddle() ? $member->riddle_approved_at !== null : $member->confirmed_at !== null)
            && $record->auth_mode === AuthMode::current() && $record->expires_at->isFuture()
            && $record->factor_generation === $member->factor_generation
            && hash_equals($record->credential_fingerprint, self::fingerprint($user, $member));
    }

    public function requireUnlocked(Request $request): void
    {
        $user = $this->actor($request);
        $member = Enrollment::member($user);
        $grant = $this->grants($request, $user)->first();
        if (! $member || ! $grant || ! $this->valid($member, $user, $grant)) {
            $this->grants($request, $user)->delete();
            abort(423, __('The vault is locked. Complete vault authentication again.'));
        }
    }

    /** @return array{grant_id:string,expires_at:CarbonImmutable,member:Member,fingerprint:string} */
    public function administrativeProof(Request $request): array
    {
        AuthMode::requireStrong();
        $user = $this->actor($request);
        abort_unless($user->can('vault.admin'), 403);
        $member = Enrollment::members()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        $this->requireUnlocked($request);
        $grant = $this->grants($request, $user)->lockForUpdate()->firstOrFail();

        return ['grant_id' => $grant->id, 'expires_at' => $grant->expires_at, 'member' => clone $member, 'fingerprint' => $grant->credential_fingerprint];
    }

    /** @param array{grant_id:string,expires_at:CarbonImmutable,member:Member,fingerprint:string} $proof */
    public function checkAdministrativeProof(Request $request, #[\SensitiveParameter] array $proof, bool $selfReset = false): void
    {
        $user = $this->actor($request);
        abort_unless($user->can('vault.admin') && $proof['member']->user_id === $user->id && $proof['expires_at']->isFuture(), 423);
        $member = Enrollment::member($user);
        $expected = $proof['member']->factor_generation + ($selfReset ? 1 : 0);
        abort_unless($member && $member->factor_generation === $expected && hash_equals($proof['fingerprint'], self::fingerprint($user, $proof['member'])), 423);
        if (! $selfReset) {
            abort_unless($this->grants($request, $user)->whereKey($proof['grant_id'])->exists(), 423);
            $this->requireUnlocked($request);
        }
    }

    public static function assertNoticeQueue(): void
    {
        abort_unless(config('queue.connections.database.driver') === 'database'
            && in_array(config('queue.connections.database.connection'), [null, config('database.default')], true), 503,
            __('Vault security notices require the installation database queue.'));
    }

    public function status(Request $request): array
    {
        $user = $this->actor($request);
        $member = Enrollment::member($user);
        $grant = $this->grants($request, $user)->first();
        $unlocked = $member && $grant && $this->valid($member, $user, $grant);
        if ($grant && ! $unlocked) {
            $this->grants($request, $user)->delete();
        }
        $challenge = $this->challenges($request, $user)->whereNull('completed_at')->where('expires_at', '>', now())->latest('created_at')->first();
        if ($challenge && (! $member || ! $this->valid($member, $user, $challenge))) {
            $challenge = null;
        }
        $riddle = AuthMode::riddle();
        $development = ! $riddle && DevQuestion::enabled();
        $approved = $riddle ? $member?->riddle_approved_at !== null : $member !== null;
        $riddleConfigured = $riddle && app(Riddle::class)->configured();

        return ['auth_mode' => AuthMode::current(), 'enrolled' => $riddle ? $approved : $member?->confirmed_at !== null, 'approved' => $approved,
            'unlocked' => (bool) $unlocked, 'issued_at' => $unlocked ? $grant->issued_at->toISOString() : null,
            'expires_at' => $unlocked ? $grant->expires_at->toISOString() : null, 'server_now' => now()->toISOString(),
            'step' => $challenge ? ($challenge->email_verified_at ? ($riddle ? 'riddle' : 'totp') : 'email') : ($riddle ? 'email_start' : 'password'),
            'challenge_id' => $challenge?->id, 'dev_question_mode' => $development,
            'question' => $riddleConfigured ? app(Riddle::class)->setting()?->question : ($development ? DevQuestion::QUESTION : null),
            'riddle_configured' => $riddleConfigured, 'can_admin' => ! $riddle && $user->can('vault.admin')];
    }

    /** Non-delivering transports must never receive usable challenge tokens outside tests. */
    public static function assertMailTransport(): void
    {
        $testing = app()->environment('testing');
        $local = app()->isLocal();
        $allowed = false;
        try {
            $allowed = self::directMailTransportAllowed($testing, $local);
        } catch (\Throwable) {
            // Transport construction/DSN errors may contain credentials. Never
            // report, chain or return them from this security boundary.
        }
        $message = $testing ? 'Vault tests require the non-delivering test mail transport.'
            : ($local ? 'Local vault authentication requires the isolated development mail transport.'
                : 'Configure a delivering email transport before using vault authentication.');
        abort_unless($allowed, 503, __($message));
    }

    private static function directMailTransportAllowed(bool $testing, bool $local): bool
    {
        $name = config('mail.default');
        $mailers = config('mail.mailers');
        // Vault deliberately requires modern, explicit, direct configuration.
        // Legacy driver overrides and composite fallback chains are not accepted.
        if (config('mail.driver') !== null || ! is_string($name) || $name === ''
            || ! is_array($mailers) || ! is_array($configuration = $mailers[$name] ?? null)) {
            return false;
        }
        if (($testing && $name !== 'array') || ($local && $name !== 'smtp')) {
            return false;
        }
        if (isset($configuration['url'])) {
            $url = $configuration['url'];
            if (($configuration['transport'] ?? null) !== 'smtp' || ! is_string($url)
                || ! in_array(parse_url($url, PHP_URL_SCHEME), ['smtp', 'smtps'], true)) {
                return false;
            }
            // Mirror Laravel's URL/query precedence, including a query-string
            // driver override; trusting only MAIL_URL's scheme is insufficient.
            $configuration = array_merge($configuration, (new ConfigurationUrlParser)->parseConfiguration($configuration));
            $configuration['transport'] = $configuration['driver'] ?? null;
        }
        $driver = $configuration['transport'] ?? null;
        $classes = [
            'smtp' => [EsmtpTransport::class],
            'sendmail' => [SendmailTransport::class],
            'mail' => [SendmailTransport::class],
            'ses' => [SesTransport::class],
            'ses-v2' => [SesV2Transport::class],
            'resend' => [ResendTransport::class],
            'cloudflare' => [CloudflareTransport::class],
        ];
        if ($testing) {
            $classes = ['array' => [ArrayTransport::class]];
        } elseif ($local) {
            $classes = ['smtp' => $classes['smtp']];
        }
        if (! is_string($driver) || ! isset($classes[$driver])) {
            return false;
        }
        $host = $configuration['host'] ?? null;
        if ($driver === 'smtp' && (! is_string($host) || $host === ''
            || ! in_array($configuration['scheme'] ?? 'smtp', ['smtp', 'smtps'], true)
            || ($local && ! in_array($host, ['127.0.0.1', 'localhost', 'mailpit'], true)))) {
            return false;
        }
        $manager = app('mail.manager');
        if ($manager::class !== MailManager::class) {
            return false;
        }
        // Inspect the actual cached instance too: a custom creator or previously
        // resolved log transport must not bypass the configuration checks.
        $transport = $manager->mailer($name)->getSymfonyTransport();
        if (! in_array($transport::class, $classes[$driver], true)) {
            return false;
        }
        if ($driver === 'smtp') {
            if (! $transport instanceof EsmtpTransport) {
                return false;
            }
            $stream = $transport->getStream();

            return $stream instanceof SocketStream
                && $stream->getHost() === $host;
        }

        return true;
    }

    private function attempt(User $user, string $event, callable $callback): mixed
    {
        try {
            $result = $callback();
            app(AuditLog::class)->record($user, $event, null, 'success');

            return $result;
        } catch (\Throwable $error) {
            app(AuditLog::class)->record($user, $event, null, 'failed');
            throw $error;
        }
    }

    public function password(Request $request, #[\SensitiveParameter] array $input): array
    {
        AuthMode::requireStrong();
        $user = $this->actor($request);

        return $this->attempt($user, 'vault.unlock_password', function () use ($request, $user, $input) {
            self::throttle($user, 'password');
            self::throttle($user, 'email-send', 3, 600);
            $data = validator($input, ['password' => 'required|string|max:1024'])->validate();
            self::assertMailTransport();
            $token = Str::random(64);
            $challenge = DB::transaction(function () use ($request, $user, $data, $token) {
                $member = Enrollment::members()->where('user_id', $user->id)->lockForUpdate()->first();
                if (! $member?->confirmed_at || ! $member->password_hash || ! Hash::driver('argon2id')->check($data['password'], $member->password_hash)) {
                    throw ValidationException::withMessages(['vault' => __('The vault password is invalid or enrollment is incomplete.')]);
                }
                $this->grants($request, $user)->delete();
                $this->challenges($request, $user)->delete();

                return Challenge::create(['id' => (string) Str::uuid(), 'installation_id' => Enrollment::installation(), 'user_id' => $user->id,
                    'auth_mode' => AuthMode::current(), 'session_hash' => self::sessionHash($request), 'factor_generation' => $member->factor_generation,
                    'credential_fingerprint' => self::fingerprint($user, $member), 'token_hash' => hash('sha256', $token),
                    'password_verified_at' => now(), 'expires_at' => now()->addSeconds(self::CHALLENGE_SECONDS), 'created_at' => now()]);
            });
            $this->send($user, $challenge, $token);

            return $this->status($request);
        });
    }

    public function emailStart(Request $request): array
    {
        abort_unless(AuthMode::riddle(), 403);
        $user = $this->actor($request);

        return $this->attempt($user, 'vault.unlock_email_started', function () use ($request, $user) {
            self::throttle($user, 'email-send', 3, 600);
            self::assertMailTransport();
            abort_unless(app(Riddle::class)->configured(), 503, __('Vault authentication is unavailable. Contact the installation operator.'));
            $token = Str::random(64);
            $challenge = DB::transaction(function () use ($request, $user, $token) {
                $member = Enrollment::members()->where('user_id', $user->id)->lockForUpdate()->first();
                abort_unless($member?->riddle_approved_at, 403, __('An operator must approve this Vault administrator before continuing.'));
                $user = $this->actor($request);
                $this->grants($request, $user)->delete();
                $this->challenges($request, $user)->delete();

                return Challenge::create(['id' => (string) Str::uuid(), 'installation_id' => Enrollment::installation(), 'user_id' => $user->id,
                    'auth_mode' => AuthMode::current(), 'session_hash' => self::sessionHash($request), 'factor_generation' => $member->factor_generation,
                    'credential_fingerprint' => self::fingerprint($user, $member), 'token_hash' => hash('sha256', $token),
                    'password_verified_at' => null, 'expires_at' => now()->addSeconds(self::CHALLENGE_SECONDS), 'created_at' => now()]);
            });
            $this->send($user, $challenge, $token);

            return $this->status($request);
        });
    }

    private function send(User $user, Challenge $challenge, #[\SensitiveParameter] string $token): void
    {
        $url = rtrim(config('app.url'), '/').'/vault/confirm#challenge='.rawurlencode($challenge->id).'&token='.rawurlencode($token);
        try {
            // A stale actor must never choose the recipient of proof bound to a fresh email.
            $recipient = Access::actor($user);
            $member = Enrollment::member($recipient);
            $current = $challenge->fresh();
            abort_unless($member && $current && $this->valid($member, $recipient, $current)
                && $current->token_hash && hash_equals($current->token_hash, hash('sha256', $token)), 503);
            $recipient->notifyNow(new EmailChallenge($url));
        } catch (\Throwable) {
            Challenge::where('id', $challenge->id)->where('installation_id', Enrollment::installation())->delete();
            abort(503, __('The vault email could not be delivered. Request a new challenge.'));
        }
    }

    public function resend(Request $request, #[\SensitiveParameter] array $input): array
    {
        $user = $this->actor($request);

        return $this->attempt($user, 'vault.unlock_email_resent', function () use ($request, $user, $input) {
            self::throttle($user, 'email-send', 3, 600);
            $data = validator($input, ['challenge_id' => 'required|uuid'])->validate();
            self::assertMailTransport();
            $token = Str::random(64);
            $challenge = DB::transaction(function () use ($request, $user, $data, $token) {
                $member = Enrollment::members()->where('user_id', $user->id)->lockForUpdate()->first();
                $challenge = $this->challenge($request, $user, $member, $data['challenge_id']);
                $challenge->update(['token_hash' => hash('sha256', $token), 'email_verified_at' => null]);

                return $challenge;
            });
            $this->send($user, $challenge, $token);

            return $this->status($request);
        });
    }

    private function challenge(Request $request, User $user, ?Member $member, string $id): Challenge
    {
        $challenge = $this->challenges($request, $user)->whereKey($id)->lockForUpdate()->first();
        if (! $member || ! $challenge || $challenge->completed_at || ! $this->valid($member, $user, $challenge)) {
            throw ValidationException::withMessages(['vault' => __('This vault challenge is invalid or expired. Continue in the browser where the request started.')]);
        }

        return $challenge;
    }

    public function email(Request $request, #[\SensitiveParameter] array $input): array
    {
        $user = $this->actor($request);

        return $this->attempt($user, 'vault.unlock_email', function () use ($request, $user, $input) {
            self::throttle($user, 'email-confirm');
            $data = validator($input, ['challenge_id' => 'required|uuid', 'token' => 'required|string|size:64'])->validate();
            DB::transaction(function () use ($request, $user, $data) {
                $member = Enrollment::members()->where('user_id', $user->id)->lockForUpdate()->first();
                $challenge = $this->challenge($request, $user, $member, $data['challenge_id']);
                if ($challenge->email_verified_at || ! $challenge->token_hash || ! hash_equals($challenge->token_hash, hash('sha256', $data['token']))) {
                    throw ValidationException::withMessages(['vault' => __('The vault email token is invalid, expired or already used.')]);
                }
                $challenge->update(['token_hash' => null, 'email_verified_at' => now()]);
                $this->challenge($request, $this->actor($request), $member->fresh(), $challenge->id);
            });

            return $this->status($request);
        });
    }

    public function factor(Request $request, #[\SensitiveParameter] array $input): array
    {
        $user = $this->actor($request);

        return $this->attempt($user, 'vault.unlock_factor', function () use ($request, $user, $input) {
            self::throttle($user, 'factor');
            $data = validator($input, AuthMode::riddle()
                ? ['challenge_id' => 'required|uuid', 'answer' => 'required|string|min:3|max:1024', 'code' => 'prohibited', 'recovery_code' => 'prohibited']
                : ['challenge_id' => 'required|uuid', 'code' => 'nullable|string|regex:/^[0-9]{6}$/D',
                    'recovery_code' => 'nullable|string|size:24', 'answer' => 'nullable|string|max:1024'])->validate();
            if (AuthMode::riddle()) {
                app(Riddle::class)->throttle();
            }
            DB::transaction(function () use ($request, $user, $data) {
                $member = Enrollment::members()->where('user_id', $user->id)->lockForUpdate()->first();
                $challenge = $this->challenge($request, $user, $member, $data['challenge_id']);
                if (! $challenge->email_verified_at || $challenge->token_hash !== null) {
                    throw ValidationException::withMessages(['vault' => AuthMode::riddle() ? __('Confirm the dedicated Vault email before answering the riddle.') : __('Confirm the dedicated vault email before submitting an authenticator code.')]);
                }
                if (AuthMode::riddle()) {
                    app(Riddle::class)->verify($data['answer']);
                } elseif (DevQuestion::enabled()) {
                    if (! $member->dev_question_hash || ! is_string($data['answer'] ?? null) || ! Hash::driver('argon2id')->check($data['answer'], $member->dev_question_hash)) {
                        throw ValidationException::withMessages(['vault' => __('The development-only vault answer is invalid.')]);
                    }
                } elseif (! empty($data['recovery_code'])) {
                    $found = false;
                    foreach (RecoveryCode::where('member_id', $member->id)->whereNull('used_at')->lockForUpdate()->get() as $recovery) {
                        if (Hash::driver('argon2id')->check($data['recovery_code'], $recovery->code_hash)) {
                            $recovery->update(['used_at' => now()]);
                            $found = true;
                            break;
                        }
                    }
                    if (! $found) {
                        throw ValidationException::withMessages(['vault' => __('The recovery code is invalid or already used.')]);
                    }
                } else {
                    $secret = app(Cipher::class)->decrypt($member->encrypted_totp, $member->totp_key_version, 'member:'.$user->id.':totp');
                    $counter = isset($data['code']) ? (new Google2FA)->verifyKeyNewer($secret, $data['code'], $member->last_totp_counter, 1, intdiv(now()->getTimestamp(), 30)) : false;
                    if ($counter === false) {
                        throw ValidationException::withMessages(['vault' => __('The authenticator code is invalid or was already used. Use a new code.')]);
                    }
                    $member->update(['last_totp_counter' => $counter]);
                }
                // Hash checks or lock waits must not outlive the original challenge.
                $user = $this->actor($request);
                $member = $member->fresh();
                $challenge = $this->challenge($request, $user, $member, $challenge->id);
                $challenge->update(['completed_at' => now(), 'token_hash' => null]);
                $this->grants($request, $user)->delete();
                $issued = now()->toImmutable();
                UnlockGrant::create(['id' => (string) Str::uuid(), 'installation_id' => Enrollment::installation(), 'user_id' => $user->id,
                    'auth_mode' => AuthMode::current(), 'session_hash' => self::sessionHash($request), 'factor_generation' => $member->factor_generation,
                    'credential_fingerprint' => self::fingerprint($user, $member), 'issued_at' => $issued, 'expires_at' => $issued->addSeconds(self::LIFETIME_SECONDS)]);
            });

            return $this->status($request);
        });
    }

    public function lock(Request $request): array
    {
        $user = $this->actor($request);
        DB::transaction(function () use ($request, $user) {
            $this->grants($request, $user)->delete();
            $this->challenges($request, $user)->delete();
            app(AuditLog::class)->record($user, 'vault.locked');
        });

        return $this->status($request);
    }
}
