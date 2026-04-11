<?php

declare(strict_types=1);

namespace Tests\Feature\V1;

use App\Models\Groupe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_user_groupes_with_pagination_meta(): void
    {
        $user = User::factory()->create();
        Groupe::query()->create([
            'user_id' => $user->id,
            'name' => 'Test',
            'description' => null,
            'cover_image' => null,
            'position' => 0,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/groupes')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination.per_page', 20)
            ->assertJsonPath('data.0.name', 'Test');
    }

    public function test_store_returns_201_with_groupe_payload_for_decoding(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/groupes', [
            'name' => 'Nouveau tableau',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Nouveau tableau')
            ->assertJsonPath('data.inspirations_count', 0)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'description',
                    'cover_image',
                    'position',
                    'inspirations_count',
                    'preview_thumbnails',
                    'created_at',
                ],
                'message',
                'meta',
            ]);
    }
}
