<?php

namespace Awove\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable|null $archived_at */
class IdentityRelationship extends Model
{
    protected $table = 'crm_identity_relationships';

    protected $guarded = [];

    protected $attributes = ['version' => 1, 'active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function mainContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'main_contact_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'responsible_id');
    }
}
