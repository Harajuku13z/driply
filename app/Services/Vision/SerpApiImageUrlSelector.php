<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Support\UnwrapGoogleUrl;

/**
 * Choisit l’URL d’aperçu la plus grande parmi les champs SerpAPI / Google, puis tente d’améliorer la qualité :
 * - extraire `imgurl` des liens de redirection Google ;
 * - augmenter les dimensions dans les URLs hébergées Google (=w/-h/, =s/) lorsque le CDN le permet.
 */
final class SerpApiImageUrlSelector
{
    /**
     * @param  iterable<int|string, mixed>  $urls
     */
    public static function pickBest(iterable $urls): string
    {
        $best = '';
        $bestScore = -1;

        foreach ($urls as $u) {
            $s = trim((string) $u);
            if ($s === '') {
                continue;
            }
            $score = self::scoreUrl($s);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $s;
            }
        }

        if ($best === '') {
            return '';
        }

        return self::preferHighResolutionFromGoogle($best);
    }

    /**
     * Déballage Google + imgurl + upgrade des paramètres de taille sur les CDN Google.
     */
    public static function preferHighResolutionFromGoogle(string $url): string
    {
        $u = trim($url);
        if ($u === '') {
            return '';
        }

        $u = UnwrapGoogleUrl::unwrap($u);

        $extracted = self::extractImgUrlFromGooglePageUrl($u);
        if ($extracted !== '') {
            $u = $extracted;
            $u = UnwrapGoogleUrl::unwrap($u);
        }

        return self::upgradeGoogleHostedImageDimensions($u);
    }

    /**
     * Paramètres imgurl / imageurl sur google.com (imgres, recherche, redirections).
     */
    public static function extractImgUrlFromGooglePageUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! str_contains($host, 'google.')) {
            return '';
        }

        $query = $parts['query'] ?? '';
        if (! is_string($query) || $query === '') {
            return '';
        }

        parse_str($query, $q);

        foreach (['imgurl', 'imageurl', 'image_url'] as $key) {
            if (empty($q[$key]) || ! is_string($q[$key])) {
                continue;
            }
            $decoded = self::recursiveUrlDecode($q[$key]);
            if (str_starts_with($decoded, 'http') && filter_var($decoded, FILTER_VALIDATE_URL)) {
                return $decoded;
            }
        }

        return '';
    }

    private static function recursiveUrlDecode(string $value): string
    {
        $current = $value;
        for ($i = 0; $i < 4; $i++) {
            $next = rawurldecode($current);
            if ($next === $current) {
                break;
            }
            $current = $next;
        }

        return $current;
    }

    /**
     * Augmente =w/-h/ ou =s/ sur lh3.googleusercontent, gstatic, ggpht, etc. (ne réduit jamais).
     */
    public static function upgradeGoogleHostedImageDimensions(string $url): string
    {
        $u = trim($url);
        if ($u === '' || ! self::isGoogleHostedImageHost($u)) {
            return $u;
        }

        $maxEdge = max(64, (int) config('vision.limits.max_google_image_edge', 1600));

        $upgraded = (string) preg_replace_callback(
            '/=w(\d+)-h(\d+)(-[a-z0-9]+)?/i',
            static function (array $m) use ($maxEdge): string {
                $w = (int) $m[1];
                $h = (int) $m[2];
                $suffix = $m[3] ?? '';
                if ($w <= 0 || $h <= 0) {
                    return $m[0];
                }
                $long = max($w, $h);
                if ($long >= $maxEdge) {
                    return $m[0];
                }
                $scale = $maxEdge / $long;
                $w2 = max(1, (int) round($w * $scale));
                $h2 = max(1, (int) round($h * $scale));

                return '=w'.$w2.'-h'.$h2.$suffix;
            },
            $u
        );

        $upgraded = (string) preg_replace_callback(
            '/=s(\d+)/i',
            static function (array $m) use ($maxEdge): string {
                $s = (int) $m[1];
                if ($s >= $maxEdge) {
                    return $m[0];
                }

                return '=s'.$maxEdge;
            },
            $upgraded
        );

        return $upgraded;
    }

    private static function isGoogleHostedImageHost(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return $host !== '' && (
            str_contains($host, 'googleusercontent.com')
            || str_contains($host, 'ggpht.com')
            || str_contains($host, 'gstatic.com')
        );
    }

    public static function scoreUrl(string $url): int
    {
        // Google / gstatic : ...=w800-h600-no ou =w800-h600
        if (preg_match('/=w(\d+)-h(\d+)/i', $url, $m) === 1) {
            return (int) $m[1] * (int) $m[2];
        }

        // =s800 (carré approximatif)
        if (preg_match('/=s(\d+)/i', $url, $m) === 1) {
            $d = (int) $m[1];

            return $d * $d;
        }

        // Chemins type /w_800/ /h_600/ (certaines CDNs)
        if (preg_match_all('/[_\/]([wh])(\d+)/i', $url, $matches, PREG_SET_ORDER) >= 1) {
            $w = 0;
            $h = 0;
            foreach ($matches as $mm) {
                $letter = strtolower((string) $mm[1]);
                $n = (int) $mm[2];
                if ($letter === 'w') {
                    $w = max($w, $n);
                }
                if ($letter === 'h') {
                    $h = max($h, $n);
                }
            }
            if ($w > 0 && $h > 0) {
                return $w * $h;
            }
            if ($w > 0) {
                return $w * $w;
            }
            if ($h > 0) {
                return $h * $h;
            }
        }

        $parts = parse_url($url);
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $q);
            $w = 0;
            $h = 0;
            foreach (['w', 'width', 'imgw'] as $k) {
                if (isset($q[$k]) && is_numeric($q[$k])) {
                    $w = max($w, (int) $q[$k]);
                }
            }
            foreach (['h', 'height', 'imgh'] as $k) {
                if (isset($q[$k]) && is_numeric($q[$k])) {
                    $h = max($h, (int) $q[$k]);
                }
            }
            if ($w > 0 && $h > 0) {
                return $w * $h;
            }
            if ($w > 0) {
                return $w * $w;
            }
            if ($h > 0) {
                return $h * $h;
            }
        }

        return strlen($url);
    }

    /**
     * @param  array<string, mixed>  $item  Ligne brute SerpAPI (shopping_results, etc.).
     * @return list<string>
     */
    public static function shoppingImageCandidates(array $item): array
    {
        $out = [];

        foreach (['image', 'product_image', 'product_photo'] as $k) {
            $v = trim((string) ($item[$k] ?? ''));
            if ($v !== '') {
                $out[] = $v;
            }
        }

        $list = $item['serpapi_thumbnails'] ?? [];
        if (is_array($list)) {
            foreach ($list as $u) {
                $s = trim((string) $u);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }

        foreach (['serpapi_thumbnail', 'thumbnail'] as $k) {
            $v = trim((string) ($item[$k] ?? ''));
            if ($v !== '') {
                $out[] = $v;
            }
        }

        $link = trim((string) ($item['link'] ?? $item['product_link'] ?? ''));
        if ($link !== '') {
            $unwrapped = UnwrapGoogleUrl::unwrap($link);
            $fromLink = self::extractImgUrlFromGooglePageUrl($unwrapped);
            if ($fromLink !== '') {
                $out[] = $fromLink;
            }
            $fromLink2 = self::extractImgUrlFromGooglePageUrl($link);
            if ($fromLink2 !== '' && $fromLink2 !== $fromLink) {
                $out[] = $fromLink2;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $match  Entrée brute visual_matches (Google Lens).
     * @return list<string>
     */
    public static function lensVisualMatchCandidates(array $match): array
    {
        $out = [];
        foreach (['image', 'thumbnail', 'source_image'] as $k) {
            $v = trim((string) ($match[$k] ?? ''));
            if ($v !== '') {
                $out[] = $v;
            }
        }

        $link = trim((string) ($match['link'] ?? ''));
        if ($link !== '') {
            $unwrapped = UnwrapGoogleUrl::unwrap($link);
            $fromLink = self::extractImgUrlFromGooglePageUrl($unwrapped);
            if ($fromLink !== '') {
                $out[] = $fromLink;
            }
            $fromLink2 = self::extractImgUrlFromGooglePageUrl($link);
            if ($fromLink2 !== '' && $fromLink2 !== $fromLink) {
                $out[] = $fromLink2;
            }
        }

        return $out;
    }
}
