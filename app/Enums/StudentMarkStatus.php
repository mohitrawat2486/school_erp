<?php

namespace App\Enums;

enum StudentMarkStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Verified = 'VERIFIED';
    case Locked = 'LOCKED';
}
