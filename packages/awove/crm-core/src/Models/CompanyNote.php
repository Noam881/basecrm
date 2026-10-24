<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyNote extends Model
{
    protected $table = 'crm_company_notes';

    protected $guarded = [];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'author_id');
    }
}
