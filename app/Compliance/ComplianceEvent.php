<?php

namespace App\Compliance;

use Illuminate\Database\Eloquent\Model;

class ComplianceEvent extends Model
{
    use ImmutableComplianceRecord;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
