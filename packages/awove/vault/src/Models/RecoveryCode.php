<?php

namespace Awove\Vault\Models;

use Illuminate\Database\Eloquent\Model;

class RecoveryCode extends Model
{
    protected $table = 'vault_recovery_codes';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $hidden = ['code_hash'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['used_at' => 'immutable_datetime'];
    }
}
