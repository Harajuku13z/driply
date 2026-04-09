<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleLensService;
use App\Services\PriceAnalysisService;
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
            $mock->shouldReceive('analyzeImage')->once()->andReturn(['visual_matches' => []]);
            $mock->shouldReceive('extractTopVisualMatches')->once()->andReturn([]);
        });

        $this->mock(PriceAnalysisService::class, function ($mock): void {
            $mock->shouldReceive('analyzeFromLensResults')->once()->andReturn([
                'item_type' => 'Sneakers',
                'style' => 'streetwear',
                'color' => 'blanc',
                'estimated_price_low' => 80.0,
                'estimated_price_mid' => 120.0,
                'estimated_price_high' => 180.0,
                'currency' => 'EUR',
                'confidence' => 'medium',
                'explanation' => 'Test.',
                'suggested_resale_price' => 100.0,
                'sources_analyzed' => 0,
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
                    'lens_results',
                    'price_analysis' => [
                        'item_type',
                        'estimated_price_mid',
                    ],
                ],
            ]);

        $id = $response->json('data.lens_result_id');
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id
        );
    }
}
