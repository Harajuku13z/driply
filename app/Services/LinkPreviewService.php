<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aperçu de page web robuste : titres, images, prix, favicon.
 *
 * Stratégie multi-couches :
 * 1. Téléchargement avec User-Agent navigateur réel (Chrome), fallback bot si bloqué
 * 2. Parsing DOMDocument (pas regex) pour OG, Twitter Cards, <title>, <meta description>
 * 3. JSON-LD schema.org Product / Offer / AggregateOffer (prix + image produit)
 * 4. Fallback image : itemprop="image", plus grande <img> de la page (heuristique taille)
 * 5. Favicon : apple-touch-icon → <link rel="icon"> → /favicon.ico → Google S2
 */
class LinkPreviewService
{
    private const MAX_BODY_BYTES = 800_000;

    /**
     * User-Agents classés par probabilité de succès.
     * Le premier simule Chrome desktop (passe la plupart des WAF/Cloudflare).
     *
     * @var list<array{ua: string, accept: string}>
     */
    private const UA_PROFILES = [
        [
            'ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        ],
        [
            'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
            'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ],
        [
            'ua' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
            'accept' => 'text/html,*/*;q=0.8',
        ],
    ];

    /**
     * @return array{
     *     ok: bool,
     *     title: ?string,
     *     description: ?string,
     *     image: ?string,
     *     site_name: ?string,
     *     price_amount: ?float,
     *     price_currency: ?string,
     *     canonical_url: ?string,
     *     favicon: ?string,
     *     error: ?string
     * }
     */
    public function preview(string $rawUrl): array
    {
        $empty = [
            'ok' => false,
            'title' => null,
            'description' => null,
            'image' => null,
            'site_name' => null,
            'price_amount' => null,
            'price_currency' => null,
            'canonical_url' => null,
            'favicon' => null,
            'error' => null,
        ];

        try {
            $url = $this->assertSafeHttpUrl($rawUrl);
        } catch (Throwable $e) {
            $empty['error'] = $e->getMessage();

            return $empty;
        }

        // Essayer chaque profil User-Agent jusqu'à obtenir un HTML exploitable
        $body = null;
        $finalUrl = $url;

        foreach (self::UA_PROFILES as $profile) {
            try {
                $response = Http::timeout(20)
                    ->connectTimeout(10)
                    ->withHeaders([
                        'User-Agent' => $profile['ua'],
                        'Accept' => $profile['accept'],
                        'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                        'Accept-Encoding' => 'gzip, deflate',
                        'Cache-Control' => 'no-cache',
                        'Pragma' => 'no-cache',
                    ])
                    ->withOptions([
                        'allow_redirects' => ['max' => 8, 'track_redirects' => true],
                        'verify' => true,
                    ])
                    ->get($url);

                if ($response->successful()) {
                    $body = $response->body();
                    $finalUrl = (string) ($response->effectiveUri()?->__toString() ?: $url);
                    break;
                }
            } catch (Throwable) {
                continue;
            }
        }

        if ($body === null || $body === '') {
            $empty['error'] = 'Impossible de télécharger la page (toutes les tentatives ont échoué).';

            return $empty;
        }

        try {
            $this->assertSafeHttpUrl($finalUrl);
        } catch (Throwable) {
            $empty['error'] = 'URL finale non autorisée.';

            return $empty;
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        $meta = $this->parseHtmlMeta($body, $finalUrl);
        $this->enrichFromJsonLd($body, $finalUrl, $meta);
        $this->enrichImageFallbacks($body, $finalUrl, $meta);

        // Favicon : toujours essayer
        $meta['favicon'] = $this->extractFavicon($body, $finalUrl);

        $title = $meta['title'] ?? null;
        $image = $meta['image'] ?? null;

        return [
            'ok' => ($title !== null && $title !== '') || ($image !== null && $image !== ''),
            'title' => ($title !== null && $title !== '') ? $title : null,
            'description' => $meta['description'] ?? null,
            'image' => ($image !== null && $image !== '') ? $image : null,
            'site_name' => $meta['site_name'] ?? null,
            'price_amount' => $meta['price_amount'] ?? null,
            'price_currency' => $meta['price_currency'] ?? null,
            'canonical_url' => $finalUrl,
            'favicon' => $meta['favicon'] ?? null,
            'error' => null,
        ];
    }

    // ─── Validation URL ──────────────────────────────────────────────

    /**
     * @throws \InvalidArgumentException
     */
    private function assertSafeHttpUrl(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || strlen($trimmed) > 2048) {
            throw new \InvalidArgumentException('URL invalide.');
        }

        if (! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('URL invalide.');
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        $scheme = is_string($scheme) ? Str::lower($scheme) : '';
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Schéma d\'URL non autorisé.');
        }

        $host = parse_url($trimmed, PHP_URL_HOST);
        $host = is_string($host) ? Str::lower($host) : '';
        if ($host === '' || $host === 'localhost') {
            throw new \InvalidArgumentException('Hôte non autorisé.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('Adresse IP non autorisée.');
            }
        }

        return $trimmed;
    }

    // ─── Parsing HTML meta ───────────────────────────────────────────

    /**
     * @return array{
     *     title: ?string,
     *     description: ?string,
     *     image: ?string,
     *     site_name: ?string,
     *     price_amount: ?float,
     *     price_currency: ?string,
     *     favicon: ?string,
     * }
     */
    private function parseHtmlMeta(string $html, string $baseUrl): array
    {
        $out = [
            'title' => null,
            'description' => null,
            'image' => null,
            'site_name' => null,
            'price_amount' => null,
            'price_currency' => null,
            'favicon' => null,
        ];

        $dom = $this->loadDom($html);
        if ($dom === null) {
            return $out;
        }

        $xpath = new DOMXPath($dom);

        // ── Titre (cascade de sources) ──
        $titleSources = [
            "//meta[@property='og:title']/@content",
            "//meta[@name='twitter:title']/@content",
            "//meta[@name='title']/@content",
            "//meta[@property='product:title']/@content",
        ];
        foreach ($titleSources as $expr) {
            $val = $this->firstMetaContent($xpath, $expr);
            if ($val !== null && $val !== '') {
                $out['title'] = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                break;
            }
        }

        // Fallback <title>
        if ($out['title'] === null || $out['title'] === '') {
            $nodes = $dom->getElementsByTagName('title');
            if ($nodes->length > 0) {
                $t = trim($nodes->item(0)?->textContent ?? '');
                if ($t !== '') {
                    $out['title'] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        // Fallback <h1>
        if ($out['title'] === null || $out['title'] === '') {
            $h1Nodes = $dom->getElementsByTagName('h1');
            if ($h1Nodes->length > 0) {
                $h1 = trim($h1Nodes->item(0)?->textContent ?? '');
                if ($h1 !== '' && mb_strlen($h1) <= 200) {
                    $out['title'] = html_entity_decode($h1, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        // ── Description ──
        $descSources = [
            "//meta[@property='og:description']/@content",
            "//meta[@name='twitter:description']/@content",
            "//meta[@name='description']/@content",
        ];
        foreach ($descSources as $expr) {
            $val = $this->firstMetaContent($xpath, $expr);
            if ($val !== null && $val !== '') {
                $out['description'] = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                break;
            }
        }

        // ── Image (cascade de sources) ──
        $imageSources = [
            "//meta[@property='og:image']/@content",
            "//meta[@property='og:image:secure_url']/@content",
            "//meta[@name='twitter:image']/@content",
            "//meta[@name='twitter:image:src']/@content",
            "//meta[@property='product:image']/@content",
            "//meta[@itemprop='image']/@content",
        ];
        foreach ($imageSources as $expr) {
            $val = $this->firstMetaContent($xpath, $expr);
            if ($val !== null && $val !== '') {
                $resolved = $this->resolveUrl($baseUrl, html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($this->looksLikeImage($resolved)) {
                    $out['image'] = $resolved;
                    break;
                }
            }
        }

        // Fallback : itemprop="image" sur n'importe quel élément (balise img, link, etc.)
        if ($out['image'] === null || $out['image'] === '') {
            $imgPropNodes = @$xpath->query("//*[@itemprop='image']");
            if ($imgPropNodes !== false && $imgPropNodes->length > 0) {
                $node = $imgPropNodes->item(0);
                $src = $node?->getAttribute('src') ?: $node?->getAttribute('content') ?: $node?->getAttribute('href');
                if ($src !== null && $src !== '') {
                    $resolved = $this->resolveUrl($baseUrl, html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($this->looksLikeImage($resolved)) {
                        $out['image'] = $resolved;
                    }
                }
            }
        }

        // ── Site name ──
        $site = $this->firstMetaContent($xpath, "//meta[@property='og:site_name']/@content");
        if ($site !== null && $site !== '') {
            $out['site_name'] = html_entity_decode($site, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // ── Prix via meta tags ──
        $priceMetaProps = [
            'product:price:amount',
            'og:price:amount',
        ];
        foreach ($priceMetaProps as $prop) {
            $amount = $this->firstMetaContent($xpath, "//meta[@property='{$prop}']/@content");
            if ($amount !== null && $amount !== '') {
                $parsed = $this->parsePriceScalar($amount);
                if ($parsed > 0) {
                    $out['price_amount'] = $parsed;
                    $currProp = str_replace(':amount', ':currency', $prop);
                    $curr = $this->firstMetaContent($xpath, "//meta[@property='{$currProp}']/@content");
                    $out['price_currency'] = ($curr !== null && $curr !== '') ? Str::upper(trim($curr)) : 'EUR';
                    break;
                }
            }
        }

        // Prix itemprop
        if (($out['price_amount'] ?? null) === null) {
            $priceNodes = @$xpath->query("//*[@itemprop='price']");
            if ($priceNodes !== false && $priceNodes->length > 0) {
                $priceNode = $priceNodes->item(0);
                $priceVal = $priceNode?->getAttribute('content') ?: trim($priceNode?->textContent ?? '');
                if ($priceVal !== '') {
                    $parsed = $this->parsePriceScalar($priceVal);
                    if ($parsed > 0) {
                        $out['price_amount'] = $parsed;
                        $currNodes = @$xpath->query("//*[@itemprop='priceCurrency']");
                        $curr = $currNodes !== false && $currNodes->length > 0
                            ? ($currNodes->item(0)?->getAttribute('content') ?: trim($currNodes->item(0)?->textContent ?? ''))
                            : '';
                        $out['price_currency'] = ($curr !== '') ? Str::upper(trim($curr)) : 'EUR';
                    }
                }
            }
        }

        return $out;
    }

    // ─── Image fallbacks ─────────────────────────────────────────────

    private function enrichImageFallbacks(string $html, string $baseUrl, array &$meta): void
    {
        if (($meta['image'] ?? null) !== null && $meta['image'] !== '') {
            return;
        }

        $dom = $this->loadDom($html);
        if ($dom === null) {
            return;
        }

        $xpath = new DOMXPath($dom);

        // 1. Chercher les images qui ressemblent à un produit (sélecteurs courants e-commerce)
        $productSelectors = [
            "//img[contains(@class, 'product')]",
            "//img[contains(@class, 'main-image')]",
            "//img[contains(@class, 'hero')]",
            "//img[contains(@id, 'product')]",
            "//div[contains(@class, 'product')]//img",
            "//div[contains(@class, 'gallery')]//img",
            "//figure//img",
        ];
        foreach ($productSelectors as $selector) {
            $nodes = @$xpath->query($selector);
            if ($nodes !== false && $nodes->length > 0) {
                $src = $this->bestImgSrc($nodes->item(0), $baseUrl);
                if ($src !== null) {
                    $meta['image'] = $src;

                    return;
                }
            }
        }

        // 2. Chercher la plus grande <img> (par attribut width/height ou style)
        $imgNodes = $dom->getElementsByTagName('img');
        $bestSrc = null;
        $bestScore = 0;

        for ($i = 0; $i < $imgNodes->length && $i < 60; $i++) {
            $img = $imgNodes->item($i);
            if ($img === null) {
                continue;
            }

            $src = $this->bestImgSrc($img, $baseUrl);
            if ($src === null) {
                continue;
            }

            // Ignorer les images très petites (1x1, tracking pixels)
            $w = (int) ($img->getAttribute('width') ?: 0);
            $h = (int) ($img->getAttribute('height') ?: 0);
            if (($w > 0 && $w < 50) || ($h > 0 && $h < 50)) {
                continue;
            }

            // Ignorer les images d'interface : icônes, logos nav, sprites
            $srcLower = Str::lower($src);
            if (Str::contains($srcLower, ['sprite', 'icon', 'logo', 'badge', 'flag', 'arrow', 'btn', 'button', 'loading', 'spinner', 'pixel', 'tracking', 'blank.', '1x1', 'spacer'])) {
                continue;
            }

            $classId = Str::lower(($img->getAttribute('class') ?: '').($img->getAttribute('id') ?: ''));
            if (Str::contains($classId, ['icon', 'logo', 'avatar', 'flag', 'sprite'])) {
                continue;
            }

            $score = max($w, 1) * max($h, 1);
            if ($w === 0 && $h === 0) {
                $score = 100; // par défaut si pas de dimensions
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSrc = $src;
            }
        }

        if ($bestSrc !== null && $bestScore >= 100) {
            $meta['image'] = $bestSrc;
        }
    }

    private function bestImgSrc(\DOMNode $img, string $baseUrl): ?string
    {
        // Préférer data-src (lazy loading), srcset (haute résolution), puis src
        foreach (['data-src', 'data-original', 'data-lazy-src'] as $attr) {
            $val = trim($img->attributes?->getNamedItem($attr)?->nodeValue ?? '');
            if ($val !== '' && ! str_starts_with($val, 'data:')) {
                $resolved = $this->resolveUrl($baseUrl, html_entity_decode($val, ENT_QUOTES, 'UTF-8'));
                if ($this->looksLikeImage($resolved)) {
                    return $resolved;
                }
            }
        }

        // srcset : prendre la plus grande variante
        $srcset = trim($img->attributes?->getNamedItem('srcset')?->nodeValue ?? '');
        if ($srcset !== '') {
            $largest = $this->largestFromSrcset($srcset, $baseUrl);
            if ($largest !== null) {
                return $largest;
            }
        }

        $src = trim($img->attributes?->getNamedItem('src')?->nodeValue ?? '');
        if ($src !== '' && ! str_starts_with($src, 'data:')) {
            $resolved = $this->resolveUrl($baseUrl, html_entity_decode($src, ENT_QUOTES, 'UTF-8'));
            if ($this->looksLikeImage($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    private function largestFromSrcset(string $srcset, string $baseUrl): ?string
    {
        $best = null;
        $bestW = 0;

        foreach (explode(',', $srcset) as $entry) {
            $parts = preg_split('/\s+/', trim($entry));
            if (! is_array($parts) || count($parts) < 1) {
                continue;
            }
            $url = $parts[0];
            $descriptor = $parts[1] ?? '';
            if (str_starts_with($url, 'data:')) {
                continue;
            }
            $w = 0;
            if (preg_match('/^(\d+)w$/i', $descriptor, $m)) {
                $w = (int) $m[1];
            } elseif (preg_match('/^(\d+(?:\.\d+)?)x$/i', $descriptor, $m)) {
                $w = (int) ((float) $m[1] * 1000);
            }
            if ($w > $bestW || $best === null) {
                $resolved = $this->resolveUrl($baseUrl, html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
                if ($this->looksLikeImage($resolved)) {
                    $best = $resolved;
                    $bestW = $w;
                }
            }
        }

        return $best;
    }

    private function looksLikeImage(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        // Rejeter les data URIs et les fragments JS
        if (str_starts_with($url, 'data:') || str_starts_with($url, 'javascript:')) {
            return false;
        }

        return true;
    }

    // ─── Favicon ─────────────────────────────────────────────────────

    private function extractFavicon(string $html, string $pageUrl): ?string
    {
        $parsed = parse_url($pageUrl);
        if (! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }
        $origin = $parsed['scheme'].'://'.$parsed['host'];

        $dom = $this->loadDom($html);
        if ($dom !== null) {
            $xpath = new DOMXPath($dom);

            // 1. apple-touch-icon (meilleure résolution, souvent 180×180)
            $appleTouchExprs = [
                "//link[contains(@rel, 'apple-touch-icon')]/@href",
                "//link[contains(@rel, 'apple-touch-icon-precomposed')]/@href",
            ];
            foreach ($appleTouchExprs as $expr) {
                $val = $this->firstMetaContent($xpath, $expr);
                if ($val !== null && $val !== '') {
                    return $this->resolveUrl($origin, html_entity_decode($val, ENT_QUOTES, 'UTF-8'));
                }
            }

            // 2. <link rel="icon"> (toutes variantes : icon, shortcut icon)
            $iconExprs = [
                "//link[@rel='icon']/@href",
                "//link[@rel='shortcut icon']/@href",
                "//link[contains(@rel, 'icon')]/@href",
            ];
            $bestIcon = null;
            $bestSize = 0;

            foreach ($iconExprs as $expr) {
                $nodes = @$xpath->query($expr);
                if ($nodes === false) {
                    continue;
                }
                for ($i = 0; $i < $nodes->length; $i++) {
                    $href = trim($nodes->item($i)?->nodeValue ?? '');
                    if ($href === '') {
                        continue;
                    }
                    // Chercher le sizes associé
                    $parentLink = $nodes->item($i)?->parentNode;
                    $sizes = $parentLink?->attributes?->getNamedItem('sizes')?->nodeValue ?? '';
                    $size = 0;
                    if (preg_match('/(\d+)x(\d+)/i', $sizes, $sm)) {
                        $size = (int) $sm[1];
                    }
                    // SVG vaut mieux que rien mais pas autant qu'un PNG 128+
                    if (str_ends_with(Str::lower($href), '.svg') && $size === 0) {
                        $size = 64;
                    }
                    if ($size > $bestSize || $bestIcon === null) {
                        $bestIcon = $this->resolveUrl($origin, html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
                        $bestSize = $size;
                    }
                }
            }

            if ($bestIcon !== null) {
                return $bestIcon;
            }
        }

        // 3. /favicon.ico
        try {
            $icoUrl = $origin.'/favicon.ico';
            $resp = Http::timeout(6)->head($icoUrl);
            if ($resp->successful()) {
                return $icoUrl;
            }
        } catch (Throwable) {
        }

        // 4. Google S2 (cache public fiable)
        $host = $parsed['host'];

        return 'https://www.google.com/s2/favicons?domain='.$host.'&sz=128';
    }

    // ─── JSON-LD ─────────────────────────────────────────────────────

    private function enrichFromJsonLd(string $html, string $baseUrl, array &$meta): void
    {
        $dom = $this->loadDom($html);
        if ($dom === null) {
            return;
        }

        foreach ($dom->getElementsByTagName('script') as $script) {
            $type = Str::lower(trim($script->getAttribute('type')));
            if ($type !== 'application/ld+json') {
                continue;
            }
            $text = trim($script->textContent);
            if ($text === '') {
                continue;
            }
            $data = json_decode($text, true);
            if (! is_array($data)) {
                continue;
            }
            $this->walkJsonLdNode($data, $baseUrl, $meta);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function walkJsonLdNode(array $node, string $baseUrl, array &$meta): void
    {
        // Tableau plat de schemas
        if (isset($node[0]) && is_array($node[0])) {
            foreach ($node as $item) {
                if (is_array($item)) {
                    $this->walkJsonLdNode($item, $baseUrl, $meta);
                }
            }

            return;
        }

        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $g) {
                if (is_array($g)) {
                    $this->walkJsonLdNode($g, $baseUrl, $meta);
                }
            }

            return;
        }

        $types = $node['@type'] ?? null;
        $list = [];
        if (is_string($types)) {
            $list[] = Str::lower($types);
        } elseif (is_array($types)) {
            foreach ($types as $t) {
                if (is_string($t)) {
                    $list[] = Str::lower($t);
                }
            }
        }

        $isProduct = array_intersect($list, ['product', 'individualproduct', 'productmodel', 'softwareapplication']);

        if ($isProduct) {
            if (($meta['title'] === null || $meta['title'] === '') && isset($node['name']) && is_string($node['name'])) {
                $n = trim($node['name']);
                if ($n !== '') {
                    $meta['title'] = $n;
                }
            }
            if (($meta['description'] === null || $meta['description'] === '') && isset($node['description']) && is_string($node['description'])) {
                $d = trim($node['description']);
                if ($d !== '') {
                    $meta['description'] = $d;
                }
            }
            if ($meta['image'] === null || $meta['image'] === '') {
                $img = $this->jsonLdImageToUrl($node['image'] ?? null, $baseUrl);
                if ($img !== null) {
                    $meta['image'] = $img;
                }
            }
            $offer = $node['offers'] ?? null;
            if (is_array($offer)) {
                if (isset($offer['@type']) || isset($offer['price'])) {
                    $this->applyOffer($offer, $meta);
                } else {
                    foreach ($offer as $sub) {
                        if (is_array($sub)) {
                            $this->applyOffer($sub, $meta);
                        }
                    }
                }
            }
        }

        // Article / BlogPosting / NewsArticle : titre + image
        $isArticle = array_intersect($list, ['article', 'blogposting', 'newsarticle', 'review', 'recipe']);
        if ($isArticle) {
            if (($meta['title'] === null || $meta['title'] === '') && isset($node['headline']) && is_string($node['headline'])) {
                $meta['title'] = trim($node['headline']);
            }
            if (($meta['image'] === null || $meta['image'] === '')) {
                $img = $this->jsonLdImageToUrl($node['image'] ?? $node['thumbnailUrl'] ?? null, $baseUrl);
                if ($img !== null) {
                    $meta['image'] = $img;
                }
            }
        }
    }

    private function applyOffer(array $offer, array &$meta): void
    {
        if (($meta['price_amount'] ?? null) !== null && ($meta['price_amount'] ?? 0) > 0) {
            return;
        }
        $rawPrice = $offer['price'] ?? $offer['lowPrice'] ?? $offer['highPrice'] ?? null;
        if ($rawPrice === null) {
            return;
        }
        $parsed = $this->parsePriceScalar(is_scalar($rawPrice) ? (string) $rawPrice : '');
        if ($parsed <= 0) {
            return;
        }
        $meta['price_amount'] = $parsed;
        $curr = $offer['priceCurrency'] ?? null;
        $meta['price_currency'] = is_string($curr) && trim($curr) !== ''
            ? Str::upper(trim($curr))
            : 'EUR';
    }

    private function jsonLdImageToUrl(mixed $image, string $baseUrl): ?string
    {
        if (is_string($image)) {
            $t = trim($image);

            return $t === '' ? null : $this->resolveUrl($baseUrl, $t);
        }
        if (is_array($image)) {
            if (isset($image['url']) && is_string($image['url'])) {
                $t = trim($image['url']);

                return $t === '' ? null : $this->resolveUrl($baseUrl, $t);
            }
            if (isset($image[0])) {
                $first = $image[0];
                if (is_string($first)) {
                    $t = trim($first);

                    return $t === '' ? null : $this->resolveUrl($baseUrl, $t);
                }
                if (is_array($first) && isset($first['url']) && is_string($first['url'])) {
                    $t = trim($first['url']);

                    return $t === '' ? null : $this->resolveUrl($baseUrl, $t);
                }
            }
        }

        return null;
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function loadDom(string $html): ?DOMDocument
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $wrapped = '<?xml encoding="UTF-8">'.$html;
        if (@$dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR) === false) {
            libxml_clear_errors();

            return null;
        }
        libxml_clear_errors();

        return $dom;
    }

    private function firstMetaContent(DOMXPath $xpath, string $expression): ?string
    {
        $nodes = @$xpath->query($expression);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $v = trim((string) $nodes->item(0)?->nodeValue);

        return $v === '' ? null : $v;
    }

    private function resolveUrl(string $base, string $relative): string
    {
        $relative = trim($relative);
        if ($relative === '') {
            return $base;
        }
        if (Str::startsWith($relative, ['http://', 'https://'])) {
            return $relative;
        }
        if (Str::startsWith($relative, '//')) {
            return 'https:'.$relative;
        }

        $baseParts = parse_url($base);
        if (! is_array($baseParts) || ! isset($baseParts['scheme'], $baseParts['host'])) {
            return $relative;
        }
        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';
        $path = $baseParts['path'] ?? '/';
        if ($relative[0] === '/') {
            return $scheme.'://'.$host.$port.$relative;
        }
        $dir = dirname($path);
        if ($dir === '.' || $dir === '\\') {
            $dir = '/';
        }
        $dir = rtrim(str_replace('\\', '/', $dir), '/');

        return $scheme.'://'.$host.$port.$dir.'/'.$relative;
    }

    private function parsePriceScalar(string $value): float
    {
        $s = trim($value);
        if ($s === '') {
            return 0.0;
        }
        if (is_numeric($s)) {
            return (float) $s;
        }
        $s = str_replace(["\xc2\xa0", ' '], '', $s);
        $s = str_ireplace(['€', 'eur', '$', 'usd', '£', 'gbp'], '', $s);
        $s = trim($s);
        if ($s === '') {
            return 0.0;
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return is_numeric($s) ? (float) $s : 0.0;
    }
}
