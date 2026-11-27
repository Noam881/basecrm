<?php

namespace Awove\Finance\History\Files;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Retained journal and final Finance attachment; never serialize private proof or storage attributes.
 *
 * @property array $source_metadata
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $committed_at
 * @property CarbonImmutable|null $reconciled_at
 */
class Attachment extends Model
{
    protected $table = 'finance_history_attachments';

    protected $guarded = [];

    protected $hidden = ['operation_id', 'record_id', 'entry_id', 'record_mapping_id', 'entry_mapping_id', 'boundary', 'storage_boundary', 'private_path', 'sha256', 'source_metadata'];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['source_metadata' => 'array', 'size_bytes' => 'integer', 'created_at' => 'immutable_datetime', 'committed_at' => 'immutable_datetime', 'reconciled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if ($row->getOriginal('state') !== 'reserved' || array_diff(array_keys($row->getDirty()), ['state', 'committed_at', 'reconciled_by', 'reconciled_at']) !== []) {
                throw new \LogicException('Finance attachment evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Finance attachments and transfer journals are retained.'));
    }

    public function source(): Source
    {
        return Source::from($this->source_metadata);
    }
}
