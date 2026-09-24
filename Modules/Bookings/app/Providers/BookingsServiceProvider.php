<?php

namespace Modules\Bookings\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Bookings\Console\ExpirePendingPaymentBookingsCommand;
use Modules\Bookings\Contracts\MeetingProvider;
use Modules\Bookings\Meetings\FakeMeetingProvider;
use Modules\Bookings\Meetings\GoogleMeetProvider;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Policies\BookingPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class BookingsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Bookings';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'bookings';

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
     * Console commands to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ExpirePendingPaymentBookingsCommand::class,
    ];

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        parent::register();

        // Plan §9.7: the meeting driver is chosen by config('bookings.meeting_driver').
        $this->app->bind(MeetingProvider::class, fn () => match (config('bookings.meeting_driver')) {
            'google' => app(GoogleMeetProvider::class),
            default => app(FakeMeetingProvider::class),
        });
    }

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        parent::boot();

        $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);

        Gate::policy(Booking::class, BookingPolicy::class);
    }

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        // Plan §9.1: cancel stale pending_payment bookings every minute.
        $schedule->command('bookings:expire-pending')
            ->everyMinute()
            ->withoutOverlapping();
    }
}
