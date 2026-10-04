<?php

namespace Modules\JoinRequests\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class JoinRequestsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'JoinRequests';

    protected string $nameLower = 'joinrequests';

    protected array $providers = [EventServiceProvider::class, RouteServiceProvider::class];

    public function boot(): void
    {
        parent::boot();
        $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
    }
}
