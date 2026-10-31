<?php

namespace Awove\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeDocument extends Model
{
    protected $table = 'knowledge_documents';

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
            if ($r->isDirty(['name', 'url', 'entry_id', 'renewal_id', 'created_by'])) {
                throw new \LogicException('Document references are retained; add a replacement and archive the old reference.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Archive document references to retain history.'));
    }
}
