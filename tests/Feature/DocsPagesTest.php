<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DocsPagesTest extends TestCase
{
    public function test_home_page_shows_driply(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Driply', false);
    }

    public function test_redoc_docs_page_loads(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('redoc', false)
            ->assertSee('/openapi.yaml', false);
    }

    public function test_openapi_yaml_is_served(): void
    {
        $this->get('/openapi.yaml')
            ->assertOk()
            ->assertHeaderContains('content-type', 'yaml')
            ->assertSee('openapi: 3.0.3', false);
    }

    public function test_ios_guide_markdown_is_served(): void
    {
        $this->get('/docs/guide-ios')
            ->assertOk()
            ->assertHeaderContains('content-type', 'markdown')
            ->assertSee('Driply', false);
    }
}
