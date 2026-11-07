<?php

namespace App\Payroll;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $currency
 * @property int $monthly_base_minor
 * @property list<array{name: string, side: string, amount_minor: int}> $components
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 */
class CompensationAgreement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'effective_to' => 'immutable_date', 'components' => 'array'];
    }
}
