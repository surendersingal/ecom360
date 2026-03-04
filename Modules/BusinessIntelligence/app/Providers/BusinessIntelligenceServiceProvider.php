<?php

namespace Modules\BusinessIntelligence\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\BusinessIntelligence\Jobs\RefreshKpisJob;
use Modules\BusinessIntelligence\Services\AlertService;
use Modules\BusinessIntelligence\Services\BenchmarkService;
use Modules\BusinessIntelligence\Services\ExportService;
use Modules\BusinessIntelligence\Services\KpiService;
use Modules\BusinessIntelligence\Services\PredictionService;
use Modules\BusinessIntelligence\Services\QueryBuilderService;
use Modules\BusinessIntelligence\Services\ReportService;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BusinessIntelligenceServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'BusinessIntelligence';

    protected string $nameLower = 'businessintelligence';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);

        // BI services
        $this->app->singleton(QueryBuilderService::class);
        $this->app->singleton(ReportService::class);
        $this->app->singleton(KpiService::class);
        $this->app->singleton(AlertService::class);
        $this->app->singleton(PredictionService::class);
        $this->app->singleton(BenchmarkService::class);
        $this->app->singleton(ExportService::class);
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        // $this->commands([]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            // Refresh all tenant KPIs every 15 minutes
            $schedule->job(new RefreshKpisJob(), 'bi')->everyFifteenMinutes()->name('bi:refresh-kpis')->withoutOverlapping();

            // Evaluate alerts every 5 minutes
            $schedule->call(function () {
                $tenantIds = \DB::table('tenants')->pluck('id');
                $service = app(AlertService::class);
                foreach ($tenantIds as $tid) {
                    $service->evaluateAll((string) $tid);
                }
            })->everyFiveMinutes()->name('bi:evaluate-alerts')->withoutOverlapping();
        });
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/'.$this->nameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->nameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
            $this->loadJsonTranslationsFrom(module_path($this->name, 'lang'));
        }
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $relativeConfigPath = config('modules.paths.generator.config.path');
        $configPath = module_path($this->name, $relativeConfigPath);

        if (is_dir($configPath)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relativePath = str_replace($configPath . DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $configKey = $this->nameLower . '.' . str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $relativePath);
                    $key = ($relativePath === 'config.php') ? $this->nameLower : $configKey;

                    $this->publishes([$file->getPathname() => config_path($relativePath)], 'config');
                    $this->mergeConfigFrom($file->getPathname(), $key);
                }
            }
        }
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->nameLower);
        $sourcePath = module_path($this->name, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);

        $componentNamespace = $this->module_namespace($this->name, $this->app_path(config('modules.paths.generator.component-class.path')));
        Blade::componentNamespace($componentNamespace, $this->nameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->nameLower)) {
                $paths[] = $path.'/modules/'.$this->nameLower;
            }
        }

        return $paths;
    }
}
