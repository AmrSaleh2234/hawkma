<?php

namespace Modules\Consultants\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Users\Models\User;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Consultants';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        parent::boot();

        // {consultant} resolves only User rows with type = consultant
        // (an admin user id is a 404).
        Route::bind('consultant', fn ($id) => User::consultants()->findOrFail($id));
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapApiRoutes();
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     */
    protected function mapApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->group(module_path($this->name, '/routes/api.php'));
    }
}
