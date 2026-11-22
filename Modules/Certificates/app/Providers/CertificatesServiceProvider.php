<?php

namespace Modules\Certificates\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class CertificatesServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Certificates';

    protected string $nameLower = 'certificates';

    protected array $providers = [EventServiceProvider::class, RouteServiceProvider::class];

    public function boot(): void
    {
        parent::boot();
        $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
    }
}
