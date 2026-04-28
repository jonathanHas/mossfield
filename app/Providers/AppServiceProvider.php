<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('sync-api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        $this->guardAgainstDebugInProduction();
        $this->guardAgainstInsecureSyncUrlsInProduction();
    }

    private function guardAgainstDebugInProduction(): void
    {
        if ($this->app->environment('production') && config('app.debug')) {
            throw new \RuntimeException('APP_DEBUG must be false when APP_ENV=production.');
        }
    }

    private function guardAgainstInsecureSyncUrlsInProduction(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $mossordersUrl = (string) config('services.mossorders.base_url');
        if ($mossordersUrl !== '' && ! str_starts_with($mossordersUrl, 'https://')) {
            throw new \RuntimeException('MOSSORDERS_BASE_URL must use https:// in production.');
        }
    }
}
