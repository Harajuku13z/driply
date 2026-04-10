<?php

declare(strict_types=1);

namespace App\Enums;

enum InspirationTypeEnum: string
{
    case Scan = 'scan';
    case Photo = 'photo';
    case Tiktok = 'tiktok';
    case Instagram = 'instagram';
    case Youtube = 'youtube';
    case Other = 'other';
}
