<?php

namespace App\Compliance;

use Illuminate\Validation\ValidationException;

trait ImmutableComplianceRecord
{
    protected static function bootImmutableComplianceRecord(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['record' => 'Compliance evidence records cannot be changed.']));
        static::deleting(fn () => throw ValidationException::withMessages(['record' => 'Compliance evidence records cannot be deleted.']));
    }
}
