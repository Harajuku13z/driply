<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LensPublicImageUrl;
use Tests\TestCase;

class LensPublicImageUrlTest extends TestCase
{
    public function test_lens_relative_path_uses_driply_public_route_by_default(): void
    {
        config(['driply.lens.use_public_file_route' => true]);

        $url = LensPublicImageUrl::absoluteFromPublicDiskPath('lens/94cc58f7-9f56-4d0a-8a5f-186049c17b20.jpg');

        $this->assertStringContainsString('/driply-public/lens/94cc58f7-9f56-4d0a-8a5f-186049c17b20.jpg', $url);
        $this->assertStringNotContainsString('/storage/lens/', $url);
    }

    public function test_scans_relative_path_uses_driply_public_route_by_default(): void
    {
        config(['driply.lens.use_public_file_route' => true]);

        $url = LensPublicImageUrl::absoluteFromPublicDiskPath('scans/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg');

        $this->assertStringContainsString('/driply-public/scans/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg', $url);
        $this->assertStringNotContainsString('/storage/scans/', $url);
    }

    public function test_absolute_urls_are_unchanged(): void
    {
        $external = 'https://cdn.example.com/x.jpg';
        $this->assertSame($external, LensPublicImageUrl::absoluteFromPublicDiskPath($external));
    }
}
