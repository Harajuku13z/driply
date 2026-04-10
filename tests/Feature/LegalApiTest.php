<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class LegalApiTest extends TestCase
{
    public function test_legal_endpoint_returns_privacy_policy_url_without_auth(): void
    {
        config(['driply.privacy_policy_url' => '']);

        $response = $this->getJson('/api/legal');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $url = $response->json('data.privacy_policy_url');
        $this->assertIsString($url);
        $this->assertStringContainsString('politique-de-confidentialite', $url);
    }

    public function test_privacy_policy_url_can_be_overridden_via_config(): void
    {
        config(['driply.privacy_policy_url' => 'https://example.org/custom-privacy']);

        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('data.privacy_policy_url', 'https://example.org/custom-privacy');
    }
}
