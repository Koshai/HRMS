<?php

namespace App\Leave;

use App\Employees\EmployeeProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /** @return HasMany<LeaveRequestCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(LeaveRequestCharge::class);
    }

    /** @return HasMany<LeaveDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(LeaveDecision::class);
    }
}
