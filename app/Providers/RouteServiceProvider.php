<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
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
        $this->routes();
    }

    protected function routes(): void
    {
        // Load API routes with api middleware and /api prefix
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/api.php'));

        // Load web routes
        Route::middleware('web')
            ->group(base_path('routes/web.php'));
    }
}
