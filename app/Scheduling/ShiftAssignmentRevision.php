<?php

namespace App\Scheduling;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ShiftAssignmentRevision extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Schedule revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Schedule revisions are immutable.'));
    }
}
