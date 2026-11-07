<?php

namespace App\Payroll;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $currency
 * @property string $proration_basis
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 */
class PayrollPolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'effective_to' => 'immutable_date', 'requires_legal_review' => 'boolean'];
    }
}
