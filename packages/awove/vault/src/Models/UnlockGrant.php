<?php

namespace Awove\Vault\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $auth_mode
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable $expires_at
 */
class UnlockGrant extends Model
{
    protected $table = 'vault_unlocks';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $hidden = ['session_hash', 'credential_fingerprint'];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'factor_generation' => 'integer'];
    }
}
