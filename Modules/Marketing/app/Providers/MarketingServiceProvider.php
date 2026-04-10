<?php

namespace Modules\Marketing\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\Marketing\Channels\ChannelManager;
use Modules\Marketing\Channels\EmailProvider;
use Modules\Marketing\Channels\WhatsAppProvider;
use Modules\Marketing\Channels\RcsProvider;
use Modules\Marketing\Channels\PushProvider;
use Modules\Marketing\Channels\SmsProvider;
use Modules\Marketing\Services\CampaignService;
use Modules\Marketing\Services\ContactService;
use Modules\Marketing\Services\FlowExecutionService;
use Modules\Marketing\Services\RulesEngineService;
use Modules\Marketing\Services\TemplateService;
use Modules\Marketing\Services\VariableResolverService;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MarketingServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Marketing';

    protected string $nameLower = 'marketing';

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

        // Register channel providers with the ChannelManager
        $this->app->booted(function () {
            $manager = $this->app->make(ChannelManager::class);
            $manager->register($this->app->make(EmailProvider::class));
            $manager->register($this->app->make(WhatsAppProvider::class));
            $manager->register($this->app->make(RcsProvider::class));
            $manager->register($this->app->make(PushProvider::class));
            $manager->register($this->app->make(SmsProvider::class));
        });
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);

        // Core services
        $this->app->singleton(ChannelManager::class);
        $this->app->singleton(VariableResolverService::class);
        $this->app->singleton(RulesEngineService::class);
        $this->app->singleton(ContactService::class);
        $this->app->singleton(TemplateService::class);
        $this->app->singleton(CampaignService::class);
        $this->app->singleton(FlowExecutionService::class);
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

            // Process flow enrollments with pending delayed steps every minute
            $schedule->call(function () {
                app(FlowExecutionService::class)->processDueEnrollments();
            })->everyMinute()->name('marketing:process-flows')->withoutOverlapping();

            // Expire enrollments that have been waiting too long (e.g. stuck > 7 days)
            $schedule->call(function () {
                app(FlowExecutionService::class)->pruneStaleEnrollments();
            })->everyFiveMinutes()->name('marketing:prune-enrollments')->withoutOverlapping();
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
