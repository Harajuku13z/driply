<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacyLinkPreviewTest extends TestCase
{
    public function test_requires_legacy_key_when_configured(): void
    {
        config(['driply.legacy_api_key' => 'secret']);

        $this->postJson('/api/link_preview.php', ['url' => 'https://example.com/p'])
            ->assertForbidden();
    }

    public function test_returns_preview_from_html(): void
    {
        config(['driply.legacy_api_key' => 'secret']);

        $html = <<<'HTML'
<!DOCTYPE html>
<html><head>
<meta property="og:title" content="Robe été" />
<meta property="og:image" content="https://cdn.example/img.jpg" />
<meta property="og:site_name" content="Ma Boutique" />
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Product","name":"Robe été","offers":{"@type":"Offer","price":"49.99","priceCurrency":"EUR"}}
</script>
</head><body></body></html>
HTML;

        Http::fake([
            'https://shop.test/*' => Http::response($html, 200),
        ]);

        $this->postJson(
            '/api/link_preview.php',
            ['url' => 'https://shop.test/produit/1'],
            ['X-Driply-Key' => 'secret']
        )
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('title', 'Robe été')
            ->assertJsonPath('image', 'https://cdn.example/img.jpg')
            ->assertJsonPath('site_name', 'Ma Boutique')
            ->assertJsonPath('price_amount', 49.99)
            ->assertJsonPath('price_currency', 'EUR');
    }

    public function test_service_unavailable_without_legacy_key(): void
    {
        config(['driply.legacy_api_key' => '']);

        $this->postJson('/api/link_preview.php', ['url' => 'https://example.com'], ['X-Driply-Key' => ''])
            ->assertServiceUnavailable();
    }
}
