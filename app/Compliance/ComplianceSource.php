<?php

namespace App\Compliance;

use Illuminate\Database\Eloquent\Model;

class ComplianceSource extends Model
{
    use ImmutableComplianceRecord;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_on' => 'immutable_date'];
    }
}
