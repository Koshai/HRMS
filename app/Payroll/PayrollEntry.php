<?php

namespace App\Payroll;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $company_id
 * @property int $run_id
 * @property int $employee_profile_id
 * @property int $gross_minor
 * @property int $deductions_minor
 * @property int $net_minor
 * @property array<string, mixed> $snapshot
 * @property PayrollRun $run
 */
class PayrollEntry extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $protect = function (self $entry): void {
            if ($entry->run()->value('status') === 'finalized') {
                throw ValidationException::withMessages(['entry' => 'Finalized payslip entries cannot be changed.']);
            }
        };
        static::updating($protect);
        static::deleting($protect);
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    /** @return HasMany<PayrollPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class, 'entry_id');
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }
}
