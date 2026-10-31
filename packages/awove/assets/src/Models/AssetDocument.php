<?php

namespace Awove\Assets\Models;

use Illuminate\Database\Eloquent\Model;

class AssetDocument extends Model
{
    protected $table = 'assets_documents';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected $hidden = ['url'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->isDirty(['asset_id', 'name', 'url', 'created_by'])) {
                throw new \LogicException('Add a replacement document reference and archive the previous one.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Document references are retained.'));
    }
}
