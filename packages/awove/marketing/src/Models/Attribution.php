<?php

namespace Awove\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $lead_id
 * @property int|null $linked_campaign_id
 * @property mixed $payload
 * @property int $version
 */
class Attribution extends Model
{
    protected $table = 'marketing_attributions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'json'];
    }
}
