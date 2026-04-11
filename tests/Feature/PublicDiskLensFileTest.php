<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicDiskLensFileTest extends TestCase
{
    public function test_driply_public_route_serves_lens_file_from_public_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('lens/test-scan.jpg', 'fake-image');

        $response = $this->get('/driply-public/lens/test-scan.jpg');

        $response->assertOk();
        $this->assertSame('fake-image', $response->streamedContent());
    }

    public function test_driply_public_route_rejects_directory_traversal(): void
    {
        Storage::fake('public');

        $this->get('/driply-public/lens/../.env')->assertNotFound();
    }

    public function test_driply_public_route_serves_scan_file_from_public_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('scans/test-capture.jpg', 'fake-scan');

        $response = $this->get('/driply-public/scans/test-capture.jpg');

        $response->assertOk();
        $this->assertSame('fake-scan', $response->streamedContent());
    }
}
