<?php

namespace App\Enums;

enum EmployeeType: string
{
    case Principal = 'PRINCIPAL';
    case Teacher = 'TEACHER';
    case Administrator = 'ADMINISTRATOR';
    case Accountant = 'ACCOUNTANT';
    case Receptionist = 'RECEPTIONIST';
    case Clerk = 'CLERK';
    case Librarian = 'LIBRARIAN';
    case Other = 'OTHER';

    public function label(): string
    {
        return ucwords(strtolower($this->value));
    }
}
