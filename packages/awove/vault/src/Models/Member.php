<?php

namespace Awove\Vault\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $factor_generation
 * @property CarbonImmutable $approved_at
 * @property CarbonImmutable|null $enrollment_expires_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $riddle_approved_at
 */
class Member extends Model
{
    protected $table = 'vault_members';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $hidden = ['password_hash', 'pending_password_hash', 'encrypted_totp', 'pending_encrypted_totp', 'totp_key_version', 'pending_totp_key_version', 'enrollment_token_hash', 'enrollment_identity_hash', 'dev_question_hash'];

    protected function casts(): array
    {
        return ['approved_at' => 'immutable_datetime', 'riddle_approved_at' => 'immutable_datetime', 'enrollment_expires_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'factor_generation' => 'integer', 'last_totp_counter' => 'integer'];
    }
}
