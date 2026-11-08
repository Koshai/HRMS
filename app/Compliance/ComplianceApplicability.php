<?php

namespace App\Compliance;

use Illuminate\Database\Eloquent\Model;

class ComplianceApplicability extends Model
{
    use ImmutableComplianceRecord;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'effective_to' => 'immutable_date'];
    }
}
