<?php

namespace Awove\Vault;

use Awove\Core\Modules;
use Awove\Vault\Models\Challenge;
use Awove\Vault\Models\UnlockGrant;
use Illuminate\Auth\Events\Logout;

class RevokeSessionUnlocks
{
    public function handle(Logout $event): void
    {
        if (! app(Modules::class)->enabled('vault') || ! request()->hasSession()) {
            return;
        }
        $session = Unlocks::sessionHash(request());
        foreach ([UnlockGrant::class, Challenge::class] as $model) {
            $model::where('installation_id', Enrollment::installation())->where('user_id', $event->user->getAuthIdentifier())->where('session_hash', $session)->delete();
        }
        app(AuditLog::class)->record($event->user, 'vault.session_signed_out');
    }
}
