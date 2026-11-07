<?php

namespace App\Organization;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
