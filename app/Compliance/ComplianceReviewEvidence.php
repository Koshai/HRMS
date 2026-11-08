<?php

namespace App\Compliance;

use Illuminate\Database\Eloquent\Model;

class ComplianceReviewEvidence extends Model
{
    use ImmutableComplianceRecord;

    protected $table = 'compliance_review_evidence';

    protected $guarded = [];
}
