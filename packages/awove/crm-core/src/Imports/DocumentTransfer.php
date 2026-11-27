<?php

namespace Awove\Core\Imports;

use Illuminate\Database\Eloquent\Model;

/** One durable attempt and its retained provenance. Terminal evidence and file identity cannot be rewritten. */
class DocumentTransfer extends Model
{
    protected $table = 'crm_import_document_transfers';

    protected $guarded = [];

    protected $hidden = ['boundary', 'storage_boundary', 'operation_id', 'identity_mapping_id', 'source_item_mapping_id', 'history_mapping_id',
        'private_path', 'sha256', 'source_metadata', 'original_name', 'mime_type', 'size_bytes', 'contact_id', 'company_id'];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['source_metadata' => 'array', 'size_bytes' => 'integer', 'created_at' => 'immutable_datetime', 'reconciled_at' => 'immutable_datetime'];
    }

    public function sourceContext(): DocumentSource
    {
        $source = $this->getAttribute('source_metadata');
        abort_unless(is_array($source), 409, 'Document source evidence requires reconciliation.');

        return DocumentSource::from($source);
    }

    protected static function booted(): void
    {
        static::updating(function (self $transfer): void {
            if ($transfer->getRawOriginal('state') !== 'reserved'
                || array_diff(array_keys($transfer->getDirty()), ['state', 'document_id', 'reconciled_by', 'reconciled_at']) !== []) {
                throw new \LogicException('Document transfer identity and terminal evidence are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Document transfer evidence is retained.'));
    }
}
