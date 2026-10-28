<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Withdrawn = 'WITHDRAWN';
    case Transferred = 'TRANSFERRED';
}
