<?php

namespace Awove\Vault\Console;

use App\Models\User;
use Awove\Vault\Access;
use Awove\Vault\AuditLog;
use Awove\Vault\Cipher;
use Awove\Vault\Enrollment;
use Awove\Vault\Unlocks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/** OS/operator access plus a current enrolled administrator's password and unused TOTP. */
class Operator
{
    public static function installation(Command $command): void
    {
        abort_unless(app()->runningInConsole() && $command->option('confirm-installation') === Enrollment::installation(), 403);
    }

    public static function authenticate(Command $command): User
    {
        self::installation($command);
        $email = $command->option('operator');
        abort_unless(is_string($email) && $email !== '', 403);
        $user = Access::actor(User::where('email', $email)->firstOrFail());
        abort_unless($user->can('vault.admin') && $user->hasVerifiedEmail(), 403);
        Unlocks::throttle($user, 'operator', 5, 600);
        $password = $command->secret('Vault administrator password');
        $code = $command->secret('Current unused administrator authenticator code');
        try {
            DB::transaction(function () use ($user, $password, $code) {
                $member = Enrollment::members()->where('user_id', $user->id)->lockForUpdate()->first();
                abort_unless($member?->confirmed_at && is_string($password) && is_string($code)
                    && Hash::driver('argon2id')->check($password, $member->password_hash), 403);
                $secret = app(Cipher::class)->decrypt($member->encrypted_totp, $member->totp_key_version, 'member:'.$user->id.':totp');
                $counter = preg_match('/^[0-9]{6}$/D', $code)
                    ? (new Google2FA)->verifyKeyNewer($secret, $code, $member->last_totp_counter, 1, intdiv(now()->getTimestamp(), 30)) : false;
                abort_if($counter === false, 403);
                $member->update(['last_totp_counter' => $counter]);
                app(AuditLog::class)->record($user, 'vault.operator_verified');
            });
        } catch (\Throwable $error) {
            app(AuditLog::class)->record($user, 'vault.operator_verified', null, 'failed');
            throw $error;
        }

        return $user;
    }

    public static function invitation(Command $command, array $result): void
    {
        $command->line('One-time out-of-band enrollment invitation (shown once):');
        $command->line($result['invitation']);
        $command->line('Expires: '.$result['expires_at']);
    }
}
