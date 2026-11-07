<?php

namespace App\Payroll;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $kind
 * @property int $amount_minor
 * @property string $reason
 */
class PayrollAdjustment extends Model
{
    protected $guarded = [];
}
