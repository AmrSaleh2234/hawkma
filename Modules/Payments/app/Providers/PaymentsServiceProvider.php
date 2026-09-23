<?php

namespace Modules\Payments\Providers;

use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Gateways\FakeGateway;
use Modules\Payments\Gateways\MoyasarGateway;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PaymentsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Payments';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'payments';

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

        // Plan §9.6: the gateway driver is chosen by config('payments.driver').
        $this->app->bind(PaymentGateway::class, fn () => match (config('payments.driver')) {
            'moyasar' => app(MoyasarGateway::class),
            default => app(FakeGateway::class),
        });
    }

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        parent::boot();

        $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
    }
}
