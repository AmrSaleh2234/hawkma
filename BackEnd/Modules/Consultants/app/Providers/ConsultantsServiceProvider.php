<?php

namespace Modules\Consultants\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Bookings\Support\BookingsBusyTimeProvider;
use Modules\Consultants\Contracts\BusyTimeProvider;
use Modules\Consultants\Policies\ConsultantPolicy;
use Modules\Users\Models\User;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ConsultantsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Consultants';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'consultants';

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
     * Register the service provider.
     */
    public function register(): void
    {
        parent::register();

        // The Bookings implementation (replaced the Phase 6 null provider).
        $this->app->bind(BusyTimeProvider::class, BookingsBusyTimeProvider::class);
    }

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        parent::boot();

        $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);

        Gate::policy(User::class, ConsultantPolicy::class);
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
