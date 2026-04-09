<?php

declare(strict_types=1);

namespace App\Enums;

enum OutfitStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
