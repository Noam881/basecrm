<?php

namespace Awove\Nonprofit\Programs\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array $changed_fields */
class History extends Model
{
    protected $table = 'nonprofit_program_history';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Program history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Program history is append-only.'));
    }
}
