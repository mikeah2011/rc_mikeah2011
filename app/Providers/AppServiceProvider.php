<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
        Model::preventLazyLoading(! app()->isProduction());
        JsonResource::withoutWrapping();
        RateLimiter::for('deliveries', function (Request $request): Limit {
            $client = $request->attributes->get('api_client');

            return Limit::perMinute(config('notifications.ingress_requests_per_minute'))
                ->by($client?->id ?? $request->ip());
        });
    }
}
