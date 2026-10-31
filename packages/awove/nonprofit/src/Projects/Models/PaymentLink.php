<?php

namespace Awove\Nonprofit\Projects\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $project_id
 * @property int $payment_id
 * @property string $direction
 * @property int $version
 * @property CarbonInterface|null $archived_at
 */
class PaymentLink extends Model
{
    protected $table = 'nonprofit_project_payments';

    protected $guarded = [];

    public $timestamps = false;

    protected $hidden = ['payment_id'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
