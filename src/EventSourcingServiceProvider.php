<?php

namespace Core\EventSourcing;

use Illuminate\Support\ServiceProvider;
use Core\EventSourcing\Contracts\Repository;
use Core\EventSourcing\EventSourcedRepository;
use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\DomainEventDispatcher;

class EventSourcingServiceProvider extends ServiceProvider
{
    
    /**
     * Indicates if loading of the provider is deferred.
     * @var bool
     */
    protected $defer = true;


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
                \Core\EventSourcing\Console\ReplayEvents::class,
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