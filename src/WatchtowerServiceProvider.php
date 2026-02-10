<?php

namespace NathanPhelps\Watchtower;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use NathanPhelps\Watchtower\Commands\PruneJobsCommand;
use NathanPhelps\Watchtower\Commands\RestartCommand;
use NathanPhelps\Watchtower\Commands\StatusCommand;
use NathanPhelps\Watchtower\Commands\SupervisorCommand;
use NathanPhelps\Watchtower\Commands\TerminateCommand;
use NathanPhelps\Watchtower\Commands\WorkerCommand;
use NathanPhelps\Watchtower\Services\JobMonitor;
use NathanPhelps\Watchtower\Services\MetricsCollector;
use NathanPhelps\Watchtower\Services\WorkerManager;

class WatchtowerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMigrations();
        $this->registerGate();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerCommands();
        $this->registerEventListeners();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/watchtower.php',
            'watchtower'
        );

        $this->app->singleton(JobMonitor::class);
        $this->app->singleton(WorkerManager::class);
        $this->app->singleton(MetricsCollector::class);
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/watchtower.php' => config_path('watchtower.php'),
            ], 'watchtower-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'watchtower-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/watchtower'),
            ], 'watchtower-views');

            $this->publishes([
                __DIR__.'/../public' => public_path('vendor/watchtower'),
            ], 'watchtower-assets');
        }
    }

    /**
     * Register the package's migrations.
     */
    protected function registerMigrations(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * Register the Watchtower gate.
     */
    protected function registerGate(): void
    {
        Gate::define(config('watchtower.gate', 'viewWatchtower'), function ($user = null) {
            // By default, allow access in local environment only
            if (app()->environment('local')) {
                return true;
            }

            // In production, require explicit gate definition
            return false;
        });
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        Route::group($this->routeConfiguration(), function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * Get the route group configuration.
     */
    protected function routeConfiguration(): array
    {
        return [
            'prefix' => config('watchtower.path', 'watchtower'),
            'middleware' => array_merge(
                config('watchtower.middleware', ['web']),
                ['watchtower']
            ),
            'as' => 'watchtower.',
        ];
    }

    /**
     * Register the package views.
     */
    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'watchtower');
    }

    /**
     * Register the package commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                WorkerCommand::class,
                SupervisorCommand::class,
                PruneJobsCommand::class,
                RestartCommand::class,
                StatusCommand::class,
                TerminateCommand::class,
            ]);
        }
    }

    /**
     * Register event listeners for queue monitoring.
     */
    protected function registerEventListeners(): void
    {
        $monitor = $this->app->make(JobMonitor::class);

        Event::listen(JobQueued::class, function ($event) use ($monitor) {
            $monitor->recordJobQueued($event);
        });

        Event::listen(JobProcessing::class, function ($event) use ($monitor) {
            $monitor->recordJobStarted($event);
        });

        Event::listen(JobProcessed::class, function ($event) use ($monitor) {
            $monitor->recordJobCompleted($event);
        });

        Event::listen(JobFailed::class, function ($event) use ($monitor) {
            $monitor->recordJobFailed($event);
        });

        Event::listen(JobRetryRequested::class, function ($event) use ($monitor) {
            $monitor->recordJobRetrying($event);
        });
    }
}
