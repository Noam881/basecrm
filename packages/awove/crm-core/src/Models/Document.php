<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stored documents are retained; corrections are new uploads rather than replacements. */
class Document extends Model
{
    protected $table = 'crm_documents';

    protected $guarded = [];

    protected $hidden = ['private_path', 'sha256'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'datetime'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'uploaded_by');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Stored document metadata is immutable. Upload a new document.'));
        static::deleting(fn () => throw new \LogicException('Stored documents are retained as part of the client history.'));
    }
}
