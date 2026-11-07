<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case HR = 'hr';
    case Manager = 'manager';
    case Employee = 'employee';
}
