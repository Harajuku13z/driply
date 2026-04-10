<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class PrivacyPolicyPageTest extends TestCase
{
    public function test_privacy_policy_page_is_public_and_renders_french_title(): void
    {
        $this->get('/politique-de-confidentialite')
            ->assertOk()
            ->assertHeaderContains('content-type', 'text/html')
            ->assertSee('Politique de confidentialité', false);
    }
}
