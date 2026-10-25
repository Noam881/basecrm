<?php

namespace Awove\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class HrDocument extends Model
{
    protected $table = 'hr_documents';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record) {
            if ($record->isDirty(['name', 'url', 'employment_id', 'candidate_id', 'case_id', 'created_by'])) {
                throw new \LogicException('Document references are retained; add a replacement and archive the previous reference.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Archive document references to retain their history.'));
    }
}
