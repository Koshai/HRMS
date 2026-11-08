<?php

namespace App\Payroll;

use Illuminate\Database\Eloquent\Model;

class PayrollReviewRevision extends Model
{
    use ImmutablePayrollRecord;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['before_snapshot' => 'array', 'after_snapshot' => 'array'];
    }
}
