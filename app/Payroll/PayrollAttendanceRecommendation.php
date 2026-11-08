<?php

namespace App\Payroll;

use Illuminate\Database\Eloquent\Model;

class PayrollAttendanceRecommendation extends Model
{
    use ImmutablePayrollRecord;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['business_date' => 'immutable_date'];
    }
}
