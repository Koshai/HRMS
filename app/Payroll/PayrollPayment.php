<?php

namespace App\Payroll;

use Illuminate\Database\Eloquent\Model;

class PayrollPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_on' => 'immutable_date'];
    }
}
