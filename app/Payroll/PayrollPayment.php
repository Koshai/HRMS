<?php

namespace App\Payroll;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $amount_minor
 * @property int $actor_user_id
 * @property string $reference
 * @property CarbonImmutable $paid_on
 */
class PayrollPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_on' => 'immutable_date'];
    }
}
