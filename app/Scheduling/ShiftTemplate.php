<?php

namespace App\Scheduling;

use App\Organization\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ShiftTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['ends_next_day' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $template) {
            if ($template->isDirty(['starts_at_local', 'ends_at_local', 'ends_next_day', 'unpaid_break_minutes', 'grace_in_minutes', 'grace_out_minutes', 'location_id'])
                && $template->assignments()->exists()) {
                throw new LogicException('Assigned shift timing and location are frozen. Create a new template.');
            }
        });
        static::deleting(fn () => throw new LogicException('Shift templates are retained for schedule history.'));
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<ShiftAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }
}
