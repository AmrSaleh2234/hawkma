<?php

namespace Modules\Core\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CoreServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Core';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'core';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        parent::boot();

        // Register the module translations under the "core::" namespace.
        $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);

        $this->configureRateLimiting();
    }

    /**
     * The named rate limiters (plan Phase 12): login/register/forgot = 10
     * per minute, public = 60, authenticated = 120.
     *
     * The keys include the route name (and the user when there is one):
     * Laravel's default guest signature is just the IP, which would put
     * every guest route in one shared bucket and let 10 public page views
     * lock a guest out of login.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)
            ->by($request->ip().'|'.($request->route()?->getName() ?? 'auth')));

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)
            ->by($request->ip().'|'.($request->route()?->getName() ?? 'public')));

        RateLimiter::for('api', function (Request $request) {
            $key = $request->user('admin')?->id
                ?? $request->user('client')?->id
                ?? $request->ip();

            return Limit::perMinute(120)->by($key.'|api');
        });
    }

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
