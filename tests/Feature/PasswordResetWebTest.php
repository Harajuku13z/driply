<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_route_is_registered_for_notification_urls(): void
    {
        $relative = route('password.reset', [
            'token' => 'test-token',
            'email' => 'user@example.com',
        ], false);

        $this->assertStringContainsString('reset-password/test-token', $relative);
        $this->assertStringContainsString('email=', $relative);
    }

    public function test_password_reset_email_contains_named_route_url(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Password::sendResetLink(['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            $this->assertStringContainsString('/reset-password/', $url);
            $this->assertStringContainsString('email=', $url);

            return true;
        });
    }

    public function test_reset_password_show_page_without_email_shows_invalid_notice(): void
    {
        $this->get('/reset-password/arbitrary-token')
            ->assertOk()
            ->assertSeeText('incomplet', false);
    }

    public function test_reset_password_show_page_with_email_displays_form(): void
    {
        $this->get('/reset-password/tok-example?email='.rawurlencode('a@example.com'))
            ->assertOk()
            ->assertSeeText('Nouveau mot de passe', false);
    }

    public function test_reset_password_success_page_loads(): void
    {
        $this->get(route('password.reset.success'))
            ->assertOk()
            ->assertSeeText('Mot de passe mis à jour', false);
    }

    public function test_forgot_password_web_page_loads(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSeeText('Réinitialiser le mot de passe', false);
    }
}
