<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaTypeEnum: string
{
    case Image = 'image';
    case Video = 'video';
}
