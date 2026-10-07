<?php

namespace Modules\ActivityLogs\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\ActivityLogs\Listeners\LogModelActivity;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * Wildcard listeners receive the event name and payload; the listener
     * filters down to Models\* classes and skips the log table itself.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        'eloquent.created: *' => [LogModelActivity::class],
        'eloquent.updated: *' => [LogModelActivity::class],
        'eloquent.deleted: *' => [LogModelActivity::class],
        'eloquent.restored: *' => [LogModelActivity::class],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
