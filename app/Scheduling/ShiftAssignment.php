<?php

namespace App\Scheduling;

use App\Employees\EmployeeProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 */
class ShiftAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $assignment) {
            if (array_diff(array_keys($assignment->getDirty()), ['ends_on', 'updated_at'])) {
                throw new LogicException('Published shift assignments cannot be rewritten.');
            }
        });
        static::deleting(fn () => throw new LogicException('Published shift assignments cannot be deleted.'));
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    /** @return BelongsTo<ShiftTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    /** @return HasMany<ShiftAssignmentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ShiftAssignmentRevision::class);
    }
}
