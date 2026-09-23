<?php

namespace Modules\AccessControl\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Users\Models\User;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AccessControlServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'AccessControl';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'accesscontrol';

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

        $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);

        // The admin role has every permission, always (section 7.2).
        Gate::before(fn ($user, $ability) => ($user instanceof User && $user->hasRole('admin')) ? true : null);
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
