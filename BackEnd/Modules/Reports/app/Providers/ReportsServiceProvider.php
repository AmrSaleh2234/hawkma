<?php

namespace Modules\Reports\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Reports\Models\Report;
use Modules\Reports\Policies\ReportPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ReportsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Reports';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'reports';

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

        Gate::policy(Report::class, ReportPolicy::class);
    }
}
