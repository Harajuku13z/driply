<?php

declare(strict_types=1);

namespace App\Enums;

enum InspirationStatusEnum: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
}
