<?php

namespace Modules\Reviews\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class ReviewsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Reviews';

    protected string $nameLower = 'reviews';

    protected array $providers = [EventServiceProvider::class, RouteServiceProvider::class];

    public function boot(): void
    {
        parent::boot();
        $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
    }
}
