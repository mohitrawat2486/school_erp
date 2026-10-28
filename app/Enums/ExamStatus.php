<?php

namespace App\Enums;

enum ExamStatus: string
{
    case Draft = 'DRAFT';
    case Open = 'OPEN';
    case MarksEntry = 'MARKS_ENTRY';
    case Verification = 'VERIFICATION';
    case Approved = 'APPROVED';
    case Published = 'PUBLISHED';
    case Closed = 'CLOSED';
}
