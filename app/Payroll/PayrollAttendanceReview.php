<?php

namespace App\Payroll;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $employee_profile_id
 * @property string $source_status
 * @property string $decision
 * @property int $amount_minor
 * @property string $reason
 * @property CarbonImmutable $business_date
 */
class PayrollAttendanceReview extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['business_date' => 'immutable_date'];
    }
}
