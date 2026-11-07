<?php

namespace App\Leave;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LeaveBalanceEntry extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Leave ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Leave ledger entries are immutable.'));
    }
}
