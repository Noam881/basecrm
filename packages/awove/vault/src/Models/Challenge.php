<?php

namespace Awove\Vault\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $auth_mode
 * @property CarbonImmutable|null $password_verified_at
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
class Challenge extends Model
{
    protected $table = 'vault_challenges';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $hidden = ['token_hash', 'session_hash', 'credential_fingerprint'];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['password_verified_at' => 'immutable_datetime', 'email_verified_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'factor_generation' => 'integer'];
    }
}
