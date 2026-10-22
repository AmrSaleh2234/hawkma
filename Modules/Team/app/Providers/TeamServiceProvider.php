<?php

namespace Modules\Team\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class TeamServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Team';

    protected string $nameLower = 'team';

    protected array $providers = [EventServiceProvider::class, RouteServiceProvider::class];

    public function boot(): void
    {
        parent::boot();
        $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
    }
}
