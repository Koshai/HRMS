<?php

namespace App\Compliance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $company_id
 * @property int $created_by_user_id
 * @property int|null $reviewed_by_user_id
 * @property int $version
 * @property string $status
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 */
class CompliancePack extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $pack): void {
            if ($pack->isDirty(['company_id', 'version', 'effective_from', 'effective_to', 'created_by_user_id'])
                || $pack->getOriginal('status') === 'active') {
                throw ValidationException::withMessages(['pack' => 'Reviewed compliance pack facts cannot be changed. Create a new version.']);
            }
            $next = ['draft' => 'ready_for_legal_review', 'ready_for_legal_review' => 'reviewed', 'reviewed' => 'active'];
            if ($pack->isDirty('status') && $pack->status !== ($next[$pack->getOriginal('status')] ?? null)) {
                throw ValidationException::withMessages(['status' => 'Compliance pack status cannot skip review.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['pack' => 'Compliance pack versions cannot be deleted.']));
    }

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'effective_to' => 'immutable_date', 'reviewed_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ComplianceRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(ComplianceRule::class, 'pack_id');
    }

    /** @return HasMany<ComplianceEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ComplianceEvent::class, 'pack_id');
    }
}
