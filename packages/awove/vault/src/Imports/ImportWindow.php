<?php

namespace Awove\Vault\Imports;

use Awove\Core\Installation;
use Awove\Vault\Models\UnlockGrant;
use Awove\Vault\Unlocks;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** One actual HTTP operation cannot inherit a later unlock issued to the same session. */
final readonly class ImportWindow
{
    private const ATTRIBUTE = '_vault_monday_import_window';

    private function __construct(private string $actor, private string $session, private string $grant, private string $fingerprint,
        private string $issued, private string $expires, public CarbonImmutable $expiresAt) {}

    public static function require(Request $request): self
    {
        app(Unlocks::class)->requireUnlocked($request);
        $status = app(Unlocks::class)->status($request);
        abort_unless($status['unlocked'] && is_string($status['issued_at']) && is_string($status['expires_at']), 423);
        $actor = (string) $request->user()->getAuthIdentifier();
        $session = Unlocks::sessionHash($request);
        $grants = UnlockGrant::where('installation_id', Installation::manifest()['id'])->where('user_id', $request->user()->getAuthIdentifier())
            ->where('session_hash', $session)->limit(2)->get();
        abort_unless($grants->count() === 1, 423);
        $grant = $grants->sole();
        abort_unless($grant->issued_at->toISOString() === $status['issued_at'] && $grant->expires_at->toISOString() === $status['expires_at'], 423);
        $existing = $request->attributes->get(self::ATTRIBUTE);
        if ($existing === null) {
            $existing = new self($actor, $session, $grant->id, $grant->credential_fingerprint, $status['issued_at'], $status['expires_at'], CarbonImmutable::parse($status['expires_at']));
            $request->attributes->set(self::ATTRIBUTE, $existing);
        }
        abort_unless($existing instanceof self && $existing->actor === $actor && hash_equals($existing->session, $session)
            && $existing->grant === $grant->id && hash_equals($existing->fingerprint, $grant->credential_fingerprint)
            && $existing->issued === $status['issued_at'] && $existing->expires === $status['expires_at'] && $existing->expiresAt->isFuture(), 423);
        app(Unlocks::class)->requireUnlocked($request);

        return $existing;
    }
}
