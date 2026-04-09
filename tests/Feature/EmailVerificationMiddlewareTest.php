<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_allowed_when_email_not_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified', false);
    }

    public function test_outfits_forbidden_when_email_not_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/outfits')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_outfits_ok_when_email_verified(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/outfits')
            ->assertOk();
    }
}
