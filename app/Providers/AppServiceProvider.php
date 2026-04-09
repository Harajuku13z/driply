<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\FastServerService;
use App\Services\GoogleLensService;
use App\Services\PHashService;
use App\Services\PriceAnalysisService;
use App\Services\SerpApiService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
    }
}
