<?php

namespace App\Attendance;

use App\Employees\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime', 'business_date' => 'immutable_date'];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<AttendanceRecord, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'correction_of_id');
    }

    /** @return HasOne<AttendanceDecision, $this> */
    public function decision(): HasOne
    {
        return $this->hasOne(AttendanceDecision::class, 'correction_id');
    }

    /** @return HasMany<AttendanceRecord, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'correction_of_id');
    }
}
