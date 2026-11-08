<?php

namespace App\Payroll;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $company_id
 * @property int $run_id
 * @property int $employee_profile_id
 * @property int $actor_user_id
 * @property string $source_status
 * @property string $decision
 * @property int $amount_minor
 * @property string $reason
 * @property CarbonImmutable $business_date
 * @property string|null $source_fingerprint
 */
class PayrollAttendanceReview extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $protect = function (self $review): void {
            if (PayrollRun::query()->whereKey($review->run_id)->value('status') === 'finalized') {
                throw ValidationException::withMessages(['review' => 'Finalized attendance decisions cannot be changed.']);
            }
        };
        static::updating($protect);
        static::deleting($protect);
    }

    protected function casts(): array
    {
        return ['business_date' => 'immutable_date'];
    }
}
