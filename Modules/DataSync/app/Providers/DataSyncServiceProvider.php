<?php

declare(strict_types=1);

namespace Modules\DataSync\Providers;

use App\Events\IntegrationEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\DataSync\Listeners\HandleSyncEvent;
use Modules\DataSync\Services\DataSyncService;
use Modules\DataSync\Services\PermissionService;
use Modules\DataSync\Services\Normalizers\ProductNormalizer;
use Modules\DataSync\Services\Normalizers\CategoryNormalizer;
use Modules\DataSync\Services\Normalizers\OrderNormalizer;
use Modules\DataSync\Services\Normalizers\CustomerNormalizer;
use Modules\DataSync\Services\Normalizers\InventoryNormalizer;
use Nwidart\Modules\Traits\PathNamespace;

final class DataSyncServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'DataSync';

    protected string $nameLower = 'datasync';

    /*
    |----------------------------------------------------------------------
    | Boot
    |----------------------------------------------------------------------
    */
    public function boot(): void
    {
        $this->registerConfig();
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        // Listen for cross-module IntegrationEvents targeting DataSync.
        Event::listen(IntegrationEvent::class, HandleSyncEvent::class);
    }

    /*
    |----------------------------------------------------------------------
    | Register
    |----------------------------------------------------------------------
    */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);

        // Core services — singletons for DI everywhere.
        $this->app->singleton(DataSyncService::class);
        $this->app->singleton(PermissionService::class);

        // Normalizers
        $this->app->singleton(ProductNormalizer::class);
        $this->app->singleton(CategoryNormalizer::class);
        $this->app->singleton(OrderNormalizer::class);
        $this->app->singleton(CustomerNormalizer::class);
        $this->app->singleton(InventoryNormalizer::class);
    }

    /*
    |----------------------------------------------------------------------
    | Helpers
    |----------------------------------------------------------------------
    */
    protected function registerConfig(): void
    {
        $this->publishes([module_path($this->name, 'config/config.php') => config_path($this->nameLower . '.php')], 'config');
        $this->mergeConfigFrom(module_path($this->name, 'config/config.php'), $this->nameLower);
    }
}
