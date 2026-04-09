<?php

declare(strict_types=1);

namespace App\Enums;

enum UserPlan: string
{
    case Free = 'free';
    case Pro = 'pro';
}
