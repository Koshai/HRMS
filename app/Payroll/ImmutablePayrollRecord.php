<?php

namespace App\Payroll;

use Illuminate\Validation\ValidationException;

trait ImmutablePayrollRecord
{
    protected static function bootImmutablePayrollRecord(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['record' => 'Payroll audit records cannot be changed.']));
        static::deleting(fn () => throw ValidationException::withMessages(['record' => 'Payroll audit records cannot be deleted.']));
    }
}
