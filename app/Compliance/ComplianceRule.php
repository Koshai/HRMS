<?php

namespace App\Compliance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ComplianceRule extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            if ($rule->pack()->value('status') !== 'draft') {
                throw ValidationException::withMessages(['rule' => 'Reviewed compliance rules cannot be changed. Create a new pack version.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['rule' => 'Compliance rules cannot be deleted.']));
    }

    protected function casts(): array
    {
        return ['parameters' => 'array'];
    }

    /** @return BelongsTo<ComplianceSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ComplianceSource::class, 'source_id');
    }

    /** @return BelongsTo<CompliancePack, $this> */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(CompliancePack::class, 'pack_id');
    }
}
