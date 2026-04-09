<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VerifyEmailPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_gets_html_success_page_with_branding(): void
    {
        $user = User::factory()->unverified()->create();

        $uri = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );

        $this->get($uri)
            ->assertOk()
            ->assertHeaderContains('content-type', 'text/html')
            ->assertSee('Driply', false)
            ->assertSee('E-mail confirmé', false)
            ->assertSee($user->name, false)
            ->assertSee('Ouvrir l’application', false)
            ->assertSee('driply://email-verified', false);
    }

    public function test_api_client_gets_json_when_accept_json(): void
    {
        $user = User::factory()->unverified()->create();

        $uri = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );

        $this->withHeader('Accept', 'application/json')
            ->get($uri)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.verified', true);
    }

    public function test_invalid_signature_shows_html_page_in_browser(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get('/api/email/verify/'.$user->getKey().'/'.sha1($user->getEmailForVerification()).'?expires='.now()->addHour()->timestamp.'&signature=invalid')
            ->assertForbidden()
            ->assertSee('Driply', false)
            ->assertSee('Lien expiré ou invalide', false);
    }
}
