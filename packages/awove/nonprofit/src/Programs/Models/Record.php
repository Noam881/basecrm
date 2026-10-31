<?php

namespace Awove\Nonprofit\Programs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property int $id
 * @property int $version
 * @property int|null $owner_id
 * @property Carbon|null $archived_at
 */
abstract class Record extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['version' => 'integer', 'owner_id' => 'integer', 'program_id' => 'integer', 'location_id' => 'integer', 'group_id' => 'integer', 'contact_id' => 'integer', 'session_id' => 'integer', 'participant_id' => 'integer', 'follow_up_owner_id' => 'integer', 'active' => 'boolean', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'session_on' => 'date:Y-m-d', 'feedback_on' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d', 'completed_on' => 'date:Y-m-d', 'archived_at' => 'datetime'];
    }
}
