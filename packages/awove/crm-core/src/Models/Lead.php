<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $table = 'crm_leads';

    // Frozen legacy storage is retained for upgrade recovery and never exposed in shared DTOs.
    protected $guarded = ['attribution'];

    protected $hidden = ['attribution'];

    protected function casts(): array
    {
        return ['custom_fields' => 'array', 'attribution' => 'array', 'archived_at' => 'datetime', 'estimated_value' => 'decimal:2', 'final_value' => 'decimal:2', 'closing_date' => 'immutable_date:Y-m-d'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Status, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'owner_id');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Note, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activity(): HasMany
    {
        return $this->hasMany(Activity::class);
    }
}
