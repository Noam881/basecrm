<?php

namespace Awove\Vault;

use App\Models\User;
use Awove\Vault\Notifications\FactorsChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DevQuestion
{
    public const QUESTION = 'מה שמו של הכלב הראשון של נועם?';

    public static function enabled(): bool
    {
        if (! config('vault.development_question', false)) {
            return false;
        }
        abort_unless(app()->environment(['local', 'testing']) && config('vault.fictional_only', false), 503, __('Development vault authentication requires an explicitly fictional local environment.'));
        $connection = DB::connection();
        $database = $connection->getConfig('database');
        abort_unless(in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost'], true), 503);
        $actual = $connection->selectOne('SELECT current_database() AS database_name, current_user AS role_name');
        if (app()->environment('testing')) {
            abort_unless($database === 'base_test' && $actual->database_name === 'base_test', 503);
        } else {
            abort_unless(in_array($database, ['base_alpha', 'base_beta'], true)
                && (int) $connection->getConfig('port') === 55438 && $actual->database_name === $database && $actual->role_name === $database, 503);
        }

        return true;
    }

    public static function set(User $user, #[\SensitiveParameter] string $answer): void
    {
        abort_unless(self::enabled(), 403);
        validator(['answer' => $answer], ['answer' => 'required|string|min:1|max:1024'])->validate();
        Unlocks::assertMailTransport();
        DB::transaction(function () use ($user, $answer) {
            $member = Enrollment::members()->where('user_id', $user->id)->whereNotNull('confirmed_at')->lockForUpdate()->firstOrFail();
            $member->update(['dev_question_hash' => Hash::driver('argon2id')->make($answer), 'factor_generation' => $member->factor_generation + 1]);
            Enrollment::invalidate($user->id);
            app(AuditLog::class)->record($user, 'vault.development_factor_changed');
        });
        try {
            $user->notifyNow(new FactorsChanged);
        } catch (\Throwable) {
            abort(503, __('The development factor changed, but its security notice could not be delivered.'));
        }
    }
}
