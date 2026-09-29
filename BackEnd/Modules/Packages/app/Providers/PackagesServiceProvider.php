<?php

namespace Modules\Packages\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Packages\Console\ExpireSubscriptionsCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PackagesServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Packages';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'packages';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ExpireSubscriptionsCommand::class,
    ];

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
    }

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        // Plan §9.4: expire subscriptions daily at 00:05.
        $schedule->command('subscriptions:expire')->dailyAt('00:05');
    }
}
