<?php

namespace App\Enums;

enum GuardianRelationship: string
{
    case Father = 'FATHER';
    case Mother = 'MOTHER';
    case Guardian = 'GUARDIAN';
}
