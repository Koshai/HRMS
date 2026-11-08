<?php

namespace App\Payroll;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $company_id
 * @property int $policy_id
 * @property int $prepared_by_user_id
 * @property string $status
 * @property string $kind
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable|null $calculated_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $released_at
 * @property list<int> $selected_employee_ids
 */
class PayrollRun extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            if ($run->getOriginal('status') === 'finalized' && array_diff(array_keys($run->getDirty()), ['released_at', 'updated_at']) !== []) {
                throw ValidationException::withMessages(['run' => 'Finalized payroll cannot be changed.']);
            }
        });
        static::deleting(function (self $run): void {
            if ($run->status === 'finalized') {
                throw ValidationException::withMessages(['run' => 'Finalized payroll cannot be deleted.']);
            }
        });
    }

    protected function casts(): array
    {
        return ['period_start' => 'immutable_date', 'calculated_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime', 'selected_employee_ids' => 'array'];
    }

    /** @return HasMany<PayrollEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class, 'run_id');
    }
}
