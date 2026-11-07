<?php

namespace App\Leave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestCharge extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<LeavePolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }
}
