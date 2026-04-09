<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Extrait une requête texte exploitable (Shopping) depuis une réponse brute Google Lens SerpAPI.
 */
final class LensSerpRawQuery
{
    public static function bestShoppingQuery(array $raw): ?string
    {
        $kg = $raw['knowledge_graph'] ?? null;
        if (is_array($kg)) {
            $t = trim((string) ($kg['title'] ?? ''));
            if ($t !== '') {
                return Str::limit($t, 220, '');
            }
        }

        foreach (['visual_matches', 'shopping_results', 'products'] as $key) {
            $block = $raw[$key] ?? [];
            if (! is_array($block)) {
                continue;
            }
            foreach ($block as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $t = trim((string) ($row['title'] ?? $row['name'] ?? ''));
                if ($t !== '') {
                    return Str::limit($t, 220, '');
                }
            }
        }

        $related = $raw['related_searches'] ?? [];
        if (is_array($related)) {
            foreach ($related as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $t = trim((string) ($row['query'] ?? $row['title'] ?? ''));
                if ($t !== '') {
                    return Str::limit($t, 220, '');
                }
            }
        }

        return null;
    }
}
