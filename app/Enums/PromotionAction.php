<?php

namespace App\Enums;

enum PromotionAction: string
{
    case Promote = 'PROMOTE';
    case Repeat = 'REPEAT';
    case Withdraw = 'WITHDRAW';
    case Transfer = 'TRANSFER';
}
