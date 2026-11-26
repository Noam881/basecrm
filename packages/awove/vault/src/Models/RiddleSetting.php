<?php

namespace Awove\Vault\Models;

use Illuminate\Database\Eloquent\Model;

class RiddleSetting extends Model
{
    protected $table = 'vault_riddle_settings';

    protected $primaryKey = 'installation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['answer_hash'];

    protected function casts(): array
    {
        return ['generation' => 'integer'];
    }
}
