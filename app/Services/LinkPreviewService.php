<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aperçu de page web (boutique, article) : titres, image, prix via meta OG / Twitter et JSON-LD Product.
 */
class LinkPreviewService
{
    private const MAX_BODY_BYTES = 600000;

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
            'error' => null,
        ];

        try {
            $url = $this->assertSafeHttpUrl($rawUrl);
        } catch (Throwable $e) {
            $empty['error'] = $e->getMessage();

            return $empty;
        }

        try {
            $response = Http::timeout(18)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; DriplyLinkPreview/1.0; +https://driplyapp.fr)',
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'fr,en;q=0.9',
                ])
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);
        } catch (Throwable $e) {
            $empty['error'] = 'Impossible de télécharger la page.';

            return $empty;
        }

        if (! $response->successful()) {
            $empty['error'] = 'La page a renvoyé une erreur HTTP '.$response->status().'.';

            return $empty;
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        $finalUrl = (string) $response->effectiveUri()?->__toString() ?: $url;

        try {
            $this->assertSafeHttpUrl($finalUrl);
        } catch (Throwable) {
            $empty['error'] = 'URL finale non autorisée.';

            return $empty;
        }

        $meta = $this->parseHtmlMeta($body, $finalUrl);
        $this->enrichFromJsonLd($body, $finalUrl, $meta);

        $title = $meta['title'] ?? null;
        $image = $meta['image'] ?? null;

        if (($title === null || $title === '') && ($image === null || $image === '')) {
            return [
                'ok' => false,
                'title' => null,
                'description' => $meta['description'] ?? null,
                'image' => null,
                'site_name' => $meta['site_name'] ?? null,
                'price_amount' => $meta['price_amount'] ?? null,
                'price_currency' => $meta['price_currency'] ?? null,
                'canonical_url' => $finalUrl,
                'error' => 'Aucun titre ni image détectés sur cette page.',
            ];
        }

        return [
            'ok' => true,
            'title' => $title !== '' ? $title : null,
            'description' => $meta['description'] ?? null,
            'image' => $image !== '' ? $image : null,
            'site_name' => $meta['site_name'] ?? null,
            'price_amount' => $meta['price_amount'] ?? null,
            'price_currency' => $meta['price_currency'] ?? null,
            'canonical_url' => $finalUrl,
            'error' => null,
        ];
    }

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
            throw new \InvalidArgumentException('Schéma d’URL non autorisé.');
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

    /**
     * @return array{
     *     title: ?string,
     *     description: ?string,
     *     image: ?string,
     *     site_name: ?string,
     *     price_amount: ?float,
     *     price_currency: ?string
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
        ];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $wrapped = '<?xml encoding="UTF-8">'.$html;
        if (@$dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR) === false) {
            libxml_clear_errors();

            return $out;
        }
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $ogTitle = $this->firstMetaContent($xpath, "//meta[@property='og:title']/@content");
        if ($ogTitle !== null && $ogTitle !== '') {
            $out['title'] = html_entity_decode($ogTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $twTitle = $this->firstMetaContent($xpath, "//meta[@name='twitter:title']/@content");
        if (($out['title'] === null || $out['title'] === '') && $twTitle !== null && $twTitle !== '') {
            $out['title'] = html_entity_decode($twTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ($out['title'] === null || $out['title'] === '') {
            $nodes = $dom->getElementsByTagName('title');
            if ($nodes->length > 0) {
                $t = trim($nodes->item(0)?->textContent ?? '');
                if ($t !== '') {
                    $out['title'] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        $ogDesc = $this->firstMetaContent($xpath, "//meta[@property='og:description']/@content");
        if ($ogDesc !== null && $ogDesc !== '') {
            $out['description'] = html_entity_decode($ogDesc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $twDesc = $this->firstMetaContent($xpath, "//meta[@name='twitter:description']/@content");
            if ($twDesc !== null && $twDesc !== '') {
                $out['description'] = html_entity_decode($twDesc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } else {
                $md = $this->firstMetaContent($xpath, "//meta[@name='description']/@content");
                if ($md !== null && $md !== '') {
                    $out['description'] = html_entity_decode($md, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        $ogImage = $this->firstMetaContent($xpath, "//meta[@property='og:image']/@content");
        if ($ogImage !== null && $ogImage !== '') {
            $out['image'] = $this->resolveUrl($baseUrl, html_entity_decode($ogImage, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } else {
            $twImage = $this->firstMetaContent($xpath, "//meta[@name='twitter:image']/@content");
            if ($twImage === null || $twImage === '') {
                $twImage = $this->firstMetaContent($xpath, "//meta[@name='twitter:image:src']/@content");
            }
            if ($twImage !== null && $twImage !== '') {
                $out['image'] = $this->resolveUrl($baseUrl, html_entity_decode($twImage, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        $site = $this->firstMetaContent($xpath, "//meta[@property='og:site_name']/@content");
        if ($site !== null && $site !== '') {
            $out['site_name'] = html_entity_decode($site, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $priceAmount = $this->firstMetaContent($xpath, "//meta[@property='product:price:amount']/@content")
            ?? $this->firstMetaContent($xpath, "//meta[@property='og:price:amount']/@content");
        $priceCurrency = $this->firstMetaContent($xpath, "//meta[@property='product:price:currency']/@content")
            ?? $this->firstMetaContent($xpath, "//meta[@property='og:price:currency']/@content");

        if ($priceAmount !== null && $priceAmount !== '') {
            $parsed = $this->parsePriceScalar($priceAmount);
            if ($parsed > 0) {
                $out['price_amount'] = $parsed;
                $out['price_currency'] = ($priceCurrency !== null && $priceCurrency !== '')
                    ? Str::upper(trim($priceCurrency))
                    : 'EUR';
            }
        }

        return $out;
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

    /**
     * @param  array{
     *     title: ?string,
     *     description: ?string,
     *     image: ?string,
     *     site_name: ?string,
     *     price_amount: ?float,
     *     price_currency: ?string
     * }  $meta
     */
    private function enrichFromJsonLd(string $html, string $baseUrl, array &$meta): void
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        if (@$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR) === false) {
            libxml_clear_errors();

            return;
        }
        libxml_clear_errors();

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
     * @param  array{
     *     title: ?string,
     *     description: ?string,
     *     image: ?string,
     *     site_name: ?string,
     *     price_amount: ?float,
     *     price_currency: ?string
     * }  $meta
     */
    private function walkJsonLdNode(array $node, string $baseUrl, array &$meta): void
    {
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

        $isProduct = in_array('product', $list, true);

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
            if (($meta['image'] === null || $meta['image'] === '')) {
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
    }

    /**
     * @param  array<string, mixed>  $offer
     * @param  array{
     *     title: ?string,
     *     description: ?string,
     *     image: ?string,
     *     site_name: ?string,
     *     price_amount: ?float,
     *     price_currency: ?string
     * }  $meta
     */
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
            if (isset($image[0]) && is_string($image[0])) {
                $t = trim($image[0]);

                return $t === '' ? null : $this->resolveUrl($baseUrl, $t);
            }
        }

        return null;
    }

    private function resolveUrl(string $base, string $relative): string
    {
        $relative = trim($relative);
        if ($relative === '') {
            return $base;
        }
        if (Str::startsWith($relative, ['http://', 'https://', '//'])) {
            if (Str::startsWith($relative, '//')) {
                return 'https:'.$relative;
            }

            return $relative;
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
        $s = str_ireplace(['€', 'eur', '$', 'usd'], '', $s);
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
