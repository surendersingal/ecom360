<?php

declare(strict_types=1);

namespace Modules\Analytics\Providers;

use App\Events\IntegrationEvent;
use App\Services\WidgetRegistry;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Analytics\Console\MakeCustomerIndexes;
use Modules\Analytics\Console\MakeMongoIndexes;
use Modules\Analytics\Console\RefreshAudienceCounts;
use Modules\Analytics\Listeners\EvaluateBehavioralRules;
use Modules\Analytics\Listeners\RecordCrossModuleEvent;
use Modules\Analytics\Listeners\RecordTrackingEvent;
use Modules\Analytics\Services\AttributionService;
use Modules\Analytics\Services\AudienceBuilderService;
use Modules\Analytics\Services\EcommerceFunnelService;
use Modules\Analytics\Services\FingerprintResolutionService;
use Modules\Analytics\Services\IdentityResolutionService;
use Modules\Analytics\Services\IntentScoringService;
use Modules\Analytics\Services\LiveContextService;
use Modules\Analytics\Services\TrackingService;
use Modules\Analytics\Services\PredictiveCLVService;
use Modules\Analytics\Services\RevenueWaterfallService;
use Modules\Analytics\Services\WhyExplanationService;
use Modules\Analytics\Services\BehavioralTriggerService;
use Modules\Analytics\Services\CustomerJourneyService;
use Modules\Analytics\Services\SmartRecommendationService;
use Modules\Analytics\Services\AudienceSyncService;
use Modules\Analytics\Services\RealTimeAlertsService;
use Modules\Analytics\Services\NaturalLanguageQueryService;
use Modules\Analytics\Services\CompetitiveBenchmarkService;
use Modules\Analytics\Widgets\FunnelWidget;
use Modules\Analytics\Widgets\RevenueChartWidget;
use Modules\Analytics\Widgets\RfmDistributionWidget;
use Modules\Analytics\Widgets\TrafficOverviewWidget;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AnalyticsServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Analytics';

    protected string $nameLower = 'analytics';

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

        /*
        |----------------------------------------------------------------------
        | Register Analytics dashboard widgets with the global WidgetRegistry.
        |----------------------------------------------------------------------
        */
        /** @var WidgetRegistry $registry */
        $registry = $this->app->make(WidgetRegistry::class);
        $registry->register('analytics.revenue_chart', RevenueChartWidget::class);
        $registry->register('analytics.traffic_overview', TrafficOverviewWidget::class);
        $registry->register('analytics.rfm_distribution', RfmDistributionWidget::class);
        $registry->register('analytics.funnel', FunnelWidget::class);

        /*
        |----------------------------------------------------------------------
        | Redis Event Bus — listen for IntegrationEvents targeting Analytics.
        |----------------------------------------------------------------------
        */
        Event::listen(IntegrationEvent::class, RecordTrackingEvent::class);

        /*
        |----------------------------------------------------------------------
        | Two-way Event Bus — Analytics tracks what other modules do.
        | (AI Search, Chatbot, Marketing events are recorded in MongoDB.)
        |----------------------------------------------------------------------
        */
        Event::listen(IntegrationEvent::class, RecordCrossModuleEvent::class);

        /*
        |----------------------------------------------------------------------
        | Dynamic Rules Engine — evaluate behavioral rules on every event
        | and broadcast real-time interventions via WebSockets.
        |----------------------------------------------------------------------
        */
        Event::listen(IntegrationEvent::class, EvaluateBehavioralRules::class);
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);

        // Bind services as singletons so they can be injected everywhere.
        $this->app->singleton(LiveContextService::class);
        $this->app->singleton(IdentityResolutionService::class);
        $this->app->singleton(FingerprintResolutionService::class);
        $this->app->singleton(IntentScoringService::class);
        $this->app->singleton(AttributionService::class);
        $this->app->singleton(EcommerceFunnelService::class);
        $this->app->singleton(AudienceBuilderService::class);
        $this->app->singleton(TrackingService::class);

        // Advanced analytics services (10 differentiating features)
        $this->app->singleton(PredictiveCLVService::class);
        $this->app->singleton(RevenueWaterfallService::class);
        $this->app->singleton(WhyExplanationService::class);
        $this->app->singleton(BehavioralTriggerService::class);
        $this->app->singleton(CustomerJourneyService::class);
        $this->app->singleton(SmartRecommendationService::class);
        $this->app->singleton(AudienceSyncService::class);
        $this->app->singleton(RealTimeAlertsService::class);
        $this->app->singleton(NaturalLanguageQueryService::class);
        $this->app->singleton(CompetitiveBenchmarkService::class);
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            MakeCustomerIndexes::class,
            MakeMongoIndexes::class,
            RefreshAudienceCounts::class,
        ]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function () {
            /** @var \Illuminate\Console\Scheduling\Schedule $schedule */
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            $schedule->command('analytics:refresh-audience-counts')->hourly();

            // Behavioral triggers — evaluate every 5 minutes
            $schedule->call(function () {
                $tenantIds = \DB::table('tenants')->pluck('id');
                $service = app(BehavioralTriggerService::class);
                foreach ($tenantIds as $tid) {
                    $service->evaluateAll((string) $tid);
                }
            })->everyFiveMinutes()->name('analytics:behavioral-triggers')->withoutOverlapping();

            // Real-time alerts — evaluate every 2 minutes
            $schedule->call(function () {
                $tenantIds = \DB::table('tenants')->pluck('id');
                $service = app(RealTimeAlertsService::class);
                foreach ($tenantIds as $tid) {
                    $service->evaluate((string) $tid);
                }
            })->everyTwoMinutes()->name('analytics:realtime-alerts')->withoutOverlapping();
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
