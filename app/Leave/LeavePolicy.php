<?php

namespace App\Leave;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LeavePolicy extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Leave policy versions are immutable.'));
    }

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'effective_to' => 'immutable_date', 'allow_half_day' => 'boolean', 'allow_negative' => 'boolean'];
    }
}
