<?php

namespace Awove\Vault;

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Vault\Models\Challenge;
use Awove\Vault\Models\Member;
use Awove\Vault\Models\RiddleSetting;
use Awove\Vault\Models\UnlockGrant;
use Awove\Vault\Notifications\FactorsChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** Explicit production policy, separate from the fictional-only development question. */
final class Riddle
{
    public function setting(bool $lock = false): ?RiddleSetting
    {
        $query = RiddleSetting::whereKey(Installation::manifest()['id']);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function configured(): bool
    {
        $setting = $this->setting();

        return $this->validSetting($setting);
    }

    public function fingerprint(): string
    {
        $setting = $this->setting();
        abort_unless($setting && $this->validSetting($setting), 503, __('Vault authentication is unavailable. Contact the installation operator.'));

        return hash_hmac('sha256', json_encode([$setting->generation, $setting->answer_hash], JSON_THROW_ON_ERROR), config('app.key'));
    }

    /** Keep cache writes outside authentication DB transactions so failures cannot roll them back. */
    public function throttle(): void
    {
        $limit = 'vault:riddle:'.hash('sha256', Installation::manifest()['id']);
        abort_if(RateLimiter::tooManyAttempts($limit, 10), 429, __('Too many vault attempts. Wait before trying again.'));
        RateLimiter::hit($limit, 900);
    }

    /** Caller locks its member before this row, matching configure's installation-wide order. */
    public function verify(#[\SensitiveParameter] string $answer): void
    {
        $setting = $this->setting(true);
        abort_unless($setting && $this->validSetting($setting), 503, __('Vault authentication is unavailable. Contact the installation operator.'));
        abort_unless(Hash::driver('argon2id')->check($answer, $setting->answer_hash), 422,
            __('The Vault riddle passphrase is invalid.'));
    }

    public function configure(?string $confirmation, string $question, #[\SensitiveParameter] string $answer, #[\SensitiveParameter] string $confirmationAnswer): void
    {
        $this->assertOperator($confirmation);
        validator(['question' => $question, 'answer' => $answer, 'answer_confirmation' => $confirmationAnswer],
            ['question' => 'required|string|max:240', 'answer' => 'required|string|min:3|max:1024|confirmed'])->validate();
        abort_unless(trim($question) !== '', 422);
        $hash = Hash::driver('argon2id')->make($answer);
        DB::transaction(function () use ($confirmation, $question, $hash) {
            $this->assertOperator($confirmation);
            $installation = Installation::manifest()['id'];
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['vault-riddle-policy:'.$installation]);
            // Every factor flow locks member -> shared setting; never invert that order.
            $members = Member::where('installation_id', $installation)->orderBy('user_id')->lockForUpdate()->get();
            if ($members->contains(fn (Member $member) => $member->riddle_approved_at !== null)) {
                Unlocks::assertNoticeQueue();
            }
            $setting = $this->setting(true) ?? new RiddleSetting(['installation_id' => $installation, 'generation' => 0]);
            $setting->fill(['question' => trim($question), 'answer_hash' => $hash, 'generation' => $setting->generation + 1])->save();
            foreach ($members as $member) {
                $member->update(['factor_generation' => $member->factor_generation + 1]);
                if ($member->riddle_approved_at !== null) {
                    $user = User::find($member->user_id);
                    if ($user && $user->active && $user->hasVerifiedEmail()) {
                        $user->notify(new FactorsChanged);
                    }
                }
            }
            foreach ([UnlockGrant::class, Challenge::class] as $model) {
                $model::where('installation_id', $installation)->delete();
            }
            AuditLog::record(null, 'riddle.configuration_changed');
        });
    }

    /** Explicit operator approval adds no action permissions and changes no active strong-mode factors. */
    public function approve(?string $confirmation, User $target): void
    {
        $this->assertOperator($confirmation);
        $target = $this->approvalTarget($target);
        DB::transaction(function () use ($confirmation, $target) {
            $this->assertOperator($confirmation);
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['vault-enroll:'.Installation::manifest()['id'].':'.$target->id]);
            $member = Member::where('installation_id', Installation::manifest()['id'])->where('user_id', $target->id)->lockForUpdate()->first();
            $member ??= new Member(['installation_id' => Installation::manifest()['id'], 'user_id' => $target->id, 'factor_generation' => 0]);
            $member->fill(['riddle_approved_at' => now(), 'factor_generation' => $member->factor_generation + 1]);
            if (! $member->exists) {
                $member->fill(['approved_by' => null, 'approved_at' => now()]);
            }
            $member->save();
            Enrollment::invalidate($target->id);
            $this->approvalTarget($target);
            AuditLog::record(null, 'riddle.administrator_approved', null, 'success', ['target_user_id' => $target->id]);
        });
    }

    private function validSetting(?RiddleSetting $setting): bool
    {
        return $setting && trim($setting->question) !== '' && password_get_info($setting->answer_hash)['algoName'] === 'argon2id';
    }

    private function approvalTarget(User $target): User
    {
        $target = $target->fresh();
        $permissions = app(Modules::class)->installed()['vault']->permissions();
        abort_unless($target && $target->active && $target->hasVerifiedEmail() && $target->hasRole('Administrator')
            && $target->hasAllPermissions($permissions) && $target->can('identities.manage'), 403);

        return $target;
    }

    public function assertOperator(?string $confirmation): void
    {
        abort_unless(app()->runningInConsole() && Readiness::installationConfirmed($confirmation) && AuthMode::riddle()
            && isset(app(Modules::class)->installed()['vault']), 403);
    }
}
