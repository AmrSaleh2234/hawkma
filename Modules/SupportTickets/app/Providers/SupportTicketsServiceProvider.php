<?php

namespace Modules\SupportTickets\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class SupportTicketsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'SupportTickets';

    protected string $nameLower = 'supporttickets';

    protected array $providers = [EventServiceProvider::class, RouteServiceProvider::class];

    public function boot(): void
    {
        parent::boot();
        $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
    }
}
