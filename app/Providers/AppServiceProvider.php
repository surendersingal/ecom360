<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\IntegrationEvent;
use App\Listeners\EventBusRouter;
use App\Services\SettingsRegistry;
use App\Services\WidgetRegistry;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
        |----------------------------------------------------------------------
        | Singletons — shared infrastructure available to every module.
        |----------------------------------------------------------------------
        */
        $this->app->singleton(SettingsRegistry::class);
        $this->app->singleton(WidgetRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
        |----------------------------------------------------------------------
        | Redis Event Bus — Global Listener Registration
        |----------------------------------------------------------------------
        | Every IntegrationEvent fired from any module is routed through
        | the EventBusRouter, which inspects the module + event name and
        | dispatches the appropriate job in the target module.
        */
        Event::listen(
            IntegrationEvent::class,
            EventBusRouter::class,
        );

        /*
        |----------------------------------------------------------------------
        | Pagination — Use Bootstrap 5 styled pagination globally
        |----------------------------------------------------------------------
        */
        Paginator::useBootstrapFive();
    }
}
