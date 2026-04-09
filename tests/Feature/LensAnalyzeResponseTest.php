<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleLensService;
use App\Services\LensImagePriceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Garantit la forme JSON de POST /api/search/lens (lens_result_id = UUID chaîne, etc.),
 * alignée avec le client iOS.
 */
class LensAnalyzeResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_lens_analyze_returns_lens_result_id_as_uuid_string(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->mock(GoogleLensService::class, function ($mock): void {
            $mock->shouldReceive('absolutePublicUrlForStoredPath')->andReturn('https://example.test/storage/lens/x.jpg');
        });

        $this->mock(LensImagePriceSearchService::class, function ($mock): void {
            $mock->shouldReceive('searchAndAnalyze')->once()->andReturn([
                'all_products' => [],
                'price_analysis' => [
                    'item_type' => 'Sneakers',
                    'style' => 'streetwear',
                    'color' => 'blanc',
                    'price_low' => 80.0,
                    'price_mid' => 120.0,
                    'price_high' => 180.0,
                    'currency' => 'EUR',
                    'confidence' => 'medium',
                    'explanation' => 'Test.',
                    'suggested_resale_price' => 100.0,
                    'sources_analyzed' => 0,
                    'top_3_picks' => [],
                ],
                'top_3' => [],
            ]);
        });

        $file = UploadedFile::fake()->image('scan.jpg', 100, 100);

        $response = $this->post('/api/search/lens', [
            'image' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'lens_result_id',
                    'input_image_public_url',
                    'all_products',
                    'top_3',
                    'price_analysis' => [
                        'item_type',
                        'price_mid',
                    ],
                ],
            ])
            ->assertJsonPath('data.all_products', [])
            ->assertJsonPath('data.input_image_public_url', 'https://example.test/storage/lens/x.jpg');

        $id = $response->json('data.lens_result_id');
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id
        );
    }
}
