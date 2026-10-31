<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $module
 * @property string $source
 * @property int $record_id
 * @property int $record_version
 * @property int $user_id
 * @property Carbon|null $read_at
 */
class WorkReminder extends Model
{
    protected $table = 'crm_work_reminders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
