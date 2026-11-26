<?php

namespace Awove\Vault;

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Vault\Models\Challenge;
use Awove\Vault\Models\Member;
use Awove\Vault\Models\RecoveryCode;
use Awove\Vault\Models\UnlockGrant;
use Awove\Vault\Notifications\FactorsChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class Enrollment
{
    public static function installation(): string
    {
        return Installation::manifest()['id'];
    }

    public static function members()
    {
        return Member::where('installation_id', self::installation());
    }

    public static function member(User $user): ?Member
    {
        return self::members()->where('user_id', $user->id)->first();
    }

    /** Trusted operator bootstrap, explicitly selecting an existing verified account. */
    public function bootstrap(User $target): array
    {
        AuthMode::requireStrong();
        $target = $this->target($target);

        return DB::transaction(function () use ($target) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['vault-bootstrap:'.self::installation()]);
            $members = self::members()->lockForUpdate()->get();
            $initial = $members->first();
            $bootstrapHistory = DB::table('vault_audits')->where('installation_id', self::installation())->where('action', 'vault.bootstrap_approved');
            $reissue = $members->count() === 1 && $initial->user_id === $target->id
                && $initial->approved_by === $target->id && ! $initial->confirmed_at
                && (clone $bootstrapHistory)->where('actor_id', $target->id)->exists()
                && ! DB::table('vault_audits')->where('installation_id', self::installation())->where('actor_id', $target->id)->where('action', 'vault.factors_enrolled')->exists();
            if (($members->isNotEmpty() && ! $reissue) || ($members->isEmpty() && $bootstrapHistory->exists())) {
                throw ValidationException::withMessages(['vault' => __('A vault administrator has already been approved. Use an authorized administrative process.')]);
            }
            $permissions = app(Modules::class)->installed()['vault']->permissions();
            $target->givePermissionTo($permissions);

            $result = $this->issue($target, $target, false);
            AuditLog::record($target, 'vault.bootstrap_approved');

            return $result;
        });
    }

    /** This trusted service is reached by an unlocked administrator or protected operator command. */
    public function approve(User $actor, User $target, bool $reset = false, ?Request $request = null): array
    {
        AuthMode::requireStrong();
        $actor = Access::actor($actor);
        abort_unless($actor->can('vault.admin'), 403);
        abort_unless(! $request || $request->user()?->getAuthIdentifier() === $actor->id, 403);
        $target = $this->target($target);
        if ($reset) {
            Unlocks::assertMailTransport();
            if ($request) {
                Unlocks::assertNoticeQueue();
            }
        }
        $proof = null;
        $result = DB::transaction(function () use ($actor, $target, $reset, $request, &$proof) {
            // A stable lock order avoids administrators resetting each other in opposite order.
            self::members()->whereIn('user_id', [$actor->id, $target->id])->orderBy('user_id')->lockForUpdate()->get();
            if ($request) {
                $proof = app(Unlocks::class)->administrativeProof($request);
            }
            $result = $this->issue($actor, $target, $reset);
            if ($reset && $request) {
                // This notice contains no secrets. Database queuing joins this transaction.
                $target->notify(new FactorsChanged);
            }
            if ($request) {
                app(Unlocks::class)->checkAdministrativeProof($request, $proof, $reset && $actor->id === $target->id);
            }

            return $result;
        });
        if ($request) {
            app(Unlocks::class)->checkAdministrativeProof($request, $proof, $reset && $actor->id === $target->id);
        } elseif ($reset) {
            try {
                $target->notifyNow(new FactorsChanged);
            } catch (\Throwable) {
                // Do not report transport exceptions containing a request, address or secret.
                abort(503, __('The vault factors were invalidated, but the security notice could not be delivered. Contact the operator.'));
            }
        }

        return $result;
    }

    private function target(User $target): User
    {
        abort_unless(app(Modules::class)->enabled('vault'), 404);
        $fresh = $target->fresh();
        abort_unless($fresh && $fresh->active && $fresh->hasVerifiedEmail(), 422, __('An active account with a verified email is required.'));

        return $fresh;
    }

    public static function identity(User $user): string
    {
        $user = $user->fresh() ?? $user;

        return hash_hmac('sha256', json_encode([self::installation(), $user->id, strtolower($user->email), $user->email_verified_at?->toISOString()], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function issue(User $actor, User $target, bool $reset): array
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['vault-enroll:'.self::installation().':'.$target->id]);
        $member = self::members()->where('user_id', $target->id)->lockForUpdate()->first();
        if ($member?->confirmed_at && ! $reset) {
            throw ValidationException::withMessages(['vault' => __('This account is already enrolled. Factor replacement requires a verified administrative reset.')]);
        }
        $token = Str::random(64);
        $expiry = now()->addDay();
        $member ??= new Member(['installation_id' => self::installation(), 'user_id' => $target->id]);
        $member->fill(['approved_by' => $actor->id, 'approved_at' => now(), 'enrollment_token_hash' => hash('sha256', $token),
            'enrollment_identity_hash' => self::identity($target), 'enrollment_expires_at' => $expiry, 'pending_password_hash' => null, 'pending_encrypted_totp' => null, 'pending_totp_key_version' => null]);
        if ($reset) {
            $member->fill(['password_hash' => null, 'encrypted_totp' => null, 'totp_key_version' => null, 'confirmed_at' => null,
                'last_totp_counter' => null, 'dev_question_hash' => null, 'factor_generation' => $member->factor_generation + 1]);
        }
        $member->save();
        self::invalidate($target->id);
        RecoveryCode::where('member_id', $member->id)->delete();
        app(AuditLog::class)->record($actor, $reset ? 'vault.factors_reset' : 'vault.enrollment_approved', null, 'success', ['target_user_id' => $target->id]);

        return ['invitation' => $token, 'expires_at' => $expiry->toISOString()];
    }

    public function begin(Request $request, #[\SensitiveParameter] array $input): array
    {
        AuthMode::requireStrong();
        $actor = app(Unlocks::class)->actor($request);
        Unlocks::throttle($actor, 'enrollment', 5, 600);
        $data = validator($input, ['invitation' => 'required|string|size:64', 'password' => 'required|string|min:12|max:1024|confirmed', 'password_confirmation' => 'required|string'])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $member = self::members()->where('user_id', $actor->id)->lockForUpdate()->first();
            $this->invitation($member, $data['invitation'], $actor);
            $secret = (new Google2FA)->generateSecretKey(32);
            $encrypted = app(Cipher::class)->encrypt($secret, 'member:'.$actor->id.':pending-totp');
            $member->update(['pending_password_hash' => Hash::driver('argon2id')->make($data['password']),
                'pending_encrypted_totp' => $encrypted['ciphertext'], 'pending_totp_key_version' => $encrypted['key_version']]);
            app(AuditLog::class)->record($actor, 'vault.enrollment_started');
            $label = rawurlencode('Base CRM Vault:'.$actor->email);
            $uri = 'otpauth://totp/'.$label.'?secret='.rawurlencode($secret).'&issuer='.rawurlencode('Base CRM Vault').'&algorithm=SHA1&digits=6&period=30';

            return ['secret' => $secret, 'otpauth_uri' => $uri];
        });
    }

    private function invitation(?Member $member, #[\SensitiveParameter] string $token, User $user): void
    {
        if (! $member || $member->confirmed_at || ! $member->enrollment_expires_at?->isFuture()
            || ! $member->enrollment_identity_hash || ! hash_equals($member->enrollment_identity_hash, self::identity($user))
            || ! $member->enrollment_token_hash || ! hash_equals($member->enrollment_token_hash, hash('sha256', $token))) {
            throw ValidationException::withMessages(['vault' => __('The vault enrollment invitation is invalid or expired. Ask an authorized administrator.')]);
        }
    }

    public function confirm(Request $request, #[\SensitiveParameter] array $input): array
    {
        AuthMode::requireStrong();
        $actor = app(Unlocks::class)->actor($request);
        Unlocks::throttle($actor, 'enrollment-factor', 5, 600);
        $data = validator($input, ['code' => 'required|string|regex:/^[0-9]{6}$/D'])->validate();
        $codes = DB::transaction(function () use ($actor, $data) {
            $member = self::members()->where('user_id', $actor->id)->lockForUpdate()->first();
            if (! $member || $member->confirmed_at || ! $member->enrollment_expires_at?->isFuture()
                || ! $member->pending_encrypted_totp || ! $member->pending_password_hash
                || ! $member->enrollment_identity_hash || ! hash_equals($member->enrollment_identity_hash, self::identity($actor))) {
                throw ValidationException::withMessages(['vault' => __('Start the approved vault enrollment before confirming an authenticator.')]);
            }
            $secret = app(Cipher::class)->decrypt($member->pending_encrypted_totp, $member->pending_totp_key_version, 'member:'.$actor->id.':pending-totp');
            $counter = (new Google2FA)->verifyKeyNewer($secret, $data['code'], null, 1, intdiv(now()->getTimestamp(), 30));
            if ($counter === false) {
                throw ValidationException::withMessages(['vault' => __('The authenticator code is invalid. Try a current code.')]);
            }
            $encrypted = app(Cipher::class)->encrypt($secret, 'member:'.$actor->id.':totp');
            $member->update(['password_hash' => $member->pending_password_hash, 'encrypted_totp' => $encrypted['ciphertext'], 'totp_key_version' => $encrypted['key_version'],
                'pending_password_hash' => null, 'pending_encrypted_totp' => null, 'pending_totp_key_version' => null,
                'enrollment_token_hash' => null, 'enrollment_identity_hash' => null, 'enrollment_expires_at' => null, 'confirmed_at' => now(), 'last_totp_counter' => $counter,
                'factor_generation' => $member->factor_generation + 1]);
            self::invalidate($actor->id);
            RecoveryCode::where('member_id', $member->id)->delete();
            $codes = [];
            for ($index = 0; $index < 10; $index++) {
                $code = strtoupper(bin2hex(random_bytes(12)));
                $codes[] = $code;
                RecoveryCode::create(['member_id' => $member->id, 'code_hash' => Hash::driver('argon2id')->make($code)]);
            }
            app(AuditLog::class)->record($actor, 'vault.factors_enrolled');

            return $codes;
        });

        return [...app(Unlocks::class)->status($request), 'recovery_codes' => $codes];
    }

    public static function invalidate(int $userId): void
    {
        UnlockGrant::where('installation_id', self::installation())->where('user_id', $userId)->delete();
        Challenge::where('installation_id', self::installation())->where('user_id', $userId)->delete();
    }
}
