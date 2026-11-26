<?php

namespace Awove\Vault;

final class AuthMode
{
    public const STRONG = 'password_email_totp';

    public const EMAIL_RIDDLE = 'email_riddle';

    public static function current(): string
    {
        $mode = config('vault.auth_mode', self::STRONG);
        abort_unless(is_string($mode) && in_array($mode, [self::STRONG, self::EMAIL_RIDDLE], true), 503, __('Vault authentication is unavailable. Contact the installation operator.'));

        return $mode;
    }

    public static function riddle(): bool
    {
        return self::current() === self::EMAIL_RIDDLE;
    }

    public static function permits($user): bool
    {
        return ! self::riddle() || ($user && $user->hasRole('Administrator') && $user->can('vault.admin'));
    }

    public static function requireStrong(): void
    {
        abort_unless(self::current() === self::STRONG, 403, __('This authentication action is unavailable in the configured Vault mode.'));
    }
}
