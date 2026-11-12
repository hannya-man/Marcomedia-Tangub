<?php

namespace App\Providers;

use App\Models\ActivityLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        // Logging in and out, for the Activity Log (every other action: App\Http\Middleware\RecordActivity).
        Event::listen(Login::class, fn (Login $event) => ActivityLog::record($event->user->getAuthIdentifier(), 'login', 'Logged in'));
        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                ActivityLog::record($event->user->getAuthIdentifier(), 'logout', 'Logged out');
            }
        });
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
