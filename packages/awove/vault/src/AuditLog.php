<?php

namespace Awove\Vault;

use Awove\Core\Installation;
use Illuminate\Support\Facades\DB;

final class AuditLog
{
    public static function record($user, string $action, ?string $itemId = null, string $result = 'success', array $metadata = []): void
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $action) || ! in_array($result, ['success', 'denied', 'failed'], true)) {
            throw new \LogicException('Vault audit events accept identifiers and outcomes only.');
        }
        if (array_diff(array_keys($metadata), ['target_user_id']) || (isset($metadata['target_user_id']) && (! is_int($metadata['target_user_id']) || $metadata['target_user_id'] < 1))) {
            throw new \LogicException('Vault audit metadata accepts target identifiers only.');
        }
        DB::table('vault_audits')->insert(['installation_id' => Installation::manifest()['id'], 'actor_id' => $user?->id,
            'target_user_id' => $metadata['target_user_id'] ?? null, 'item_id' => $itemId, 'action' => $action, 'result' => $result, 'created_at' => now()]);
    }
}
