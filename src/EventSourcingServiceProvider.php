<?php

namespace Core\EventSourcing;

use Core\EventSourcing\Console\ForgetDataSubject;
use Core\EventSourcing\Console\ReplayEvents;
use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\Contracts\KeyRing;
use Core\EventSourcing\Contracts\Repository;
use Core\EventSourcing\Pii\DeveloperKeyRing;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class EventSourcingServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register the service.
     * @return void
     */
    public function register()
    {
        $this->app->singleton(EventDispatcher::class, DomainEventDispatcher::class);
        $this->app->singleton(Repository::class, EventSourcedRepository::class);
    }

    /**
     * Bootstrap the application services.
     * @return void
     */
    public function boot()
    {
        // Register package's Artisan commands.
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReplayEvents::class,
                ForgetDataSubject::class,
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     * @return array
     */
    public function provides()
    {
        return [
            EventDispatcher::class,
            Repository::class,
        ];
    }
}