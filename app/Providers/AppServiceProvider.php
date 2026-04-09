<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\FastServerService;
use App\Services\GoogleLensService;
use App\Services\PHashService;
use App\Services\PriceAnalysisService;
use App\Services\SerpApiService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SerpApiService::class);
        $this->app->singleton(GoogleLensService::class);
        $this->app->singleton(PriceAnalysisService::class);
        $this->app->singleton(FastServerService::class);
        $this->app->singleton(PHashService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('search', function (Request $request): Limit {
            $id = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            return Limit::perMinute(30)->by($id);
        });

        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            return URL::temporarySignedRoute(
                'verification.verify',
                Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });

        VerifyEmail::toMailUsing(function (object $notifiable, string $verificationUrl): MailMessage {
            /** @var User $notifiable */
            return (new MailMessage)
                ->subject(Lang::get('Verify Email Address'))
                ->view('emails.verify-email', [
                    'verificationUrl' => $verificationUrl,
                    'userName' => (string) ($notifiable->name ?? ''),
                ]);
        });
    }
}
