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
        return [];
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'contact_id');
    }
}
