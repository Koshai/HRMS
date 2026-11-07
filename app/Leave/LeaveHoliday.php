<?php

namespace App\Leave;

use Illuminate\Database\Eloquent\Model;

class LeaveHoliday extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['holiday_on' => 'immutable_date'];
    }
}
