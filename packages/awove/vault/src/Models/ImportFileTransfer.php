<?php

namespace Awove\Vault\Models;

use Illuminate\Database\Eloquent\Model;

class ImportFileTransfer extends Model
{
    protected $table = 'vault_import_file_transfers';

    protected $guarded = [];

    protected $visible = ['id', 'state'];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['source_index' => 'integer', 'size_bytes' => 'integer', 'item_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $transfer): void {
            $allowed = match ($transfer->getRawOriginal('state')) {
                'pending' => ['state', 'operation_id', 'size_bytes', 'content_hmac', 'source_hmac'],
                'reserved' => ['state', 'attachment_id', 'target_hmac', 'reconciled_by', 'reconciled_at'],
                default => [],
            };
            if (array_diff(array_keys($transfer->getDirty()), $allowed) !== []) {
                throw new \LogicException('Vault transfer ownership and accepted evidence are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Vault transfer evidence is retained.'));
    }
}
