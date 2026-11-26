<?php

namespace Awove\Vault\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $table = 'vault_files';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $visible = ['id', 'item_id', 'size_bytes', 'created_at'];
}
