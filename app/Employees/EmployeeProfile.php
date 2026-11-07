<?php

namespace App\Employees;

use App\Models\User;
use App\Organization\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_on' => 'immutable_date'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<EmploymentAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmploymentAssignment::class);
    }
}
