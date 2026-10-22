<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $table = 'crm_companies';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'company_id');
    }
}
