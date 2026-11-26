<?php

namespace Awove\Vault;

use Awove\Core\Installation;
use Awove\Vault\Models\Challenge;
use Awove\Vault\Models\Member;
use Awove\Vault\Models\UnlockGrant;
use Illuminate\Support\Facades\DB;

/** Explicit restore maintenance only. Active factors and all encrypted customer content are preserved. */
final class RestoreAuthentication
{
    public function invalidate(?string $confirmation, bool $confirmRestore): void
    {
        $this->guard($confirmation, $confirmRestore);
        abort_unless(Readiness::databaseBoundary() && Readiness::schemaReady() && Readiness::installationDataMatches(), 403);
        DB::transaction(function () use ($confirmation, $confirmRestore) {
            $this->guard($confirmation, $confirmRestore);
            $installation = Installation::manifest()['id'];
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['vault-restore-auth:'.$installation]);
            foreach (Member::where('installation_id', $installation)->orderBy('user_id')->lockForUpdate()->get() as $member) {
                $member->update(['factor_generation' => $member->factor_generation + 1,
                    'enrollment_token_hash' => null, 'enrollment_identity_hash' => null, 'enrollment_expires_at' => null,
                    'pending_password_hash' => null, 'pending_encrypted_totp' => null, 'pending_totp_key_version' => null]);
            }
            foreach ([UnlockGrant::class, Challenge::class] as $model) {
                $model::where('installation_id', $installation)->delete();
            }
            // These are installation-wide tables in the installation's dedicated database.
            DB::table('sessions')->delete();
            DB::table('magic_login_tokens')->delete();
            AuditLog::record(null, 'restore.authentication_invalidated');
            $this->guard($confirmation, $confirmRestore);
        });
    }

    private function guard(?string $confirmation, bool $confirmRestore): void
    {
        abort_unless(app()->runningInConsole() && $confirmRestore && Readiness::installationConfirmed($confirmation)
            && app()->maintenanceMode()->active(), 403);
    }
}
