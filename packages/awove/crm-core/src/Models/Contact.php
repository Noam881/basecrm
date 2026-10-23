<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    protected $table = 'crm_contacts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['preferred_contact_times' => 'array', 'preferred_contact_days' => 'array'];
    }

    /** @return HasMany<ContactNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(ContactNote::class, 'contact_id');
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'contact_id');
    }
}
