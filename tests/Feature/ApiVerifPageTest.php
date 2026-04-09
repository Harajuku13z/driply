<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiVerifPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_verif_page_loads(): void
    {
        $response = $this->get('/api-verif');

        $response->assertOk()
            ->assertSee('diagnostic API', false);
    }

    public function test_api_verif_alias_under_api_prefix(): void
    {
        $response = $this->get('/api/verif');

        $response->assertOk()
            ->assertSee('diagnostic API', false);
    }

    public function test_api_verif_json_format(): void
    {
        $response = $this->getJson('/api-verif?format=json');

        $response->assertOk()
            ->assertJsonStructure(['success', 'summary', 'checks']);
    }
}
