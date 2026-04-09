<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaPlatform: string
{
    case Tiktok = 'tiktok';
    case Instagram = 'instagram';
    case Youtube = 'youtube';
    case Other = 'other';
}
