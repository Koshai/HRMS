<?php

namespace App\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDecision extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AttendanceRecord, $this> */
    public function correction(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'correction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
