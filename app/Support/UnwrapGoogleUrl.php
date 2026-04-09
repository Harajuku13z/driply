<?php

declare(strict_types=1);

namespace App\Support;

final class UnwrapGoogleUrl
{
    /**
     * Extrait l’URL cible des liens de redirection Google (ex. /url?url=https%3A%2F%2F…).
     */
    public static function unwrap(string $url): string
    {
        $current = trim($url);
        for ($depth = 0; $depth < 3; $depth++) {
            $parts = parse_url($current);
            $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
            if (! in_array($host, ['www.google.com', 'google.com'], true)) {
                break;
            }
            parse_str($parts['query'] ?? '', $q);
            $next = $q['url'] ?? null;
            if (! is_string($next) || $next === '') {
                break;
            }
            $current = rawurldecode($next);
        }

        return $current;
    }
}
