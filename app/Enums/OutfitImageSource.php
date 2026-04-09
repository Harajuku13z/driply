<?php

declare(strict_types=1);

namespace App\Enums;

enum OutfitImageSource: string
{
    case Upload = 'upload';
    case Serpapi = 'serpapi';
    case GoogleLens = 'google_lens';
    case SocialImport = 'social_import';
}
