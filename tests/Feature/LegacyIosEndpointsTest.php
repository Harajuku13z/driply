<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LegacyIosEndpointsTest extends TestCase
{
    public function test_upload_succeeds_without_header_when_legacy_key_empty(): void
    {
        config(['driply.legacy_api_key' => '']);

        $this->post('/upload.php', [
            'file' => UploadedFile::fake()->image('outfit.jpg'),
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['url']);
    }

    public function test_upload_requires_valid_legacy_key_when_configured(): void
    {
        config(['driply.legacy_api_key' => 'hidden']);

        $this->post('/upload.php', [
            'file' => UploadedFile::fake()->image('outfit.jpg'),
        ])->assertForbidden();

        $this->post('/upload.php', [
            'file' => UploadedFile::fake()->image('outfit.jpg'),
        ], ['X-Driply-Key' => 'hidden'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_sync_returns_service_unavailable_when_legacy_key_not_configured(): void
    {
        config(['driply.legacy_api_key' => '']);

        $this->postJson('/api/sync_media.php', ['kind' => 'google_lens'])
            ->assertServiceUnavailable();
    }

    public function test_sync_succeeds_with_matching_legacy_key(): void
    {
        config(['driply.legacy_api_key' => 'sync-secret']);

        $this->postJson('/api/sync_media.php', ['kind' => 'google_lens'], ['X-Driply-Key' => 'sync-secret'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
