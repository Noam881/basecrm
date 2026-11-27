<?php

namespace Awove\Vault\Models;

use Illuminate\Database\Eloquent\Model;

class ImportEntry extends Model
{
    protected $table = 'vault_import_entries';

    protected $guarded = [];

    protected $visible = ['id', 'state'];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            if ($entry->getRawOriginal('state') !== 'pending' || array_diff(array_keys($entry->getDirty()),
                ['state', 'operation_id', 'item_id', 'source_fingerprint', 'target_fingerprint']) !== []) {
                throw new \LogicException('Vault import source identity and accepted evidence are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Vault import evidence is retained.'));
    }
}
