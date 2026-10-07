<?php

namespace Modules\ActivityLogs\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class ActivityLogsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'ActivityLogs';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'activity-logs';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
