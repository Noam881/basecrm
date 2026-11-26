<?php

namespace Awove\Vault\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use SoftDeletes;

    protected $table = 'vault_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $visible = ['id', 'title', 'type', 'level', 'version', 'created_at', 'updated_at', 'cells'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'version' => 'integer'];
    }
}
