<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WidgetInterface;

/**
 * Central registry where every module registers its dashboard widgets.
 *
 * Bound as a singleton in AppServiceProvider so each module's
 * ServiceProvider can call `register()` during boot.
 */
final class WidgetRegistry
{
    /**
     * @var array<string, class-string<WidgetInterface>>
     */
    private array $widgets = [];

    /**
     * Register a widget by a unique key.
     *
     * @param  string                          $widgetKey    Dot-notation key, e.g. "analytics.revenue_chart"
     * @param  class-string<WidgetInterface>   $widgetClass  Fully-qualified class name implementing WidgetInterface.
     */
    public function register(string $widgetKey, string $widgetClass): void
    {
        if (!is_subclass_of($widgetClass, WidgetInterface::class)) {
            throw new \InvalidArgumentException(
                "Widget [{$widgetClass}] must implement " . WidgetInterface::class,
            );
        }

        $this->widgets[$widgetKey] = $widgetClass;
    }

    /**
     * Return metadata for every registered widget.
     *
     * @return array<string, array{name: string, metadata: array<string, mixed>}>
     */
    public function getAvailableWidgets(): array
    {
        $available = [];

        foreach ($this->widgets as $key => $class) {
            /** @var WidgetInterface $widget */
            $widget = app($class);

            $available[$key] = [
                'name'     => $widget->getName(),
                'metadata' => $widget->getMetadata(),
            ];
        }

        return $available;
    }

    /**
     * Resolve a single widget by key and return its live data.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function resolveWidget(string $widgetKey, array $params = []): array
    {
        if (!isset($this->widgets[$widgetKey])) {
            throw new \RuntimeException("Widget [{$widgetKey}] is not registered.");
        }

        /** @var WidgetInterface $widget */
        $widget = app($this->widgets[$widgetKey]);

        return $widget->resolveData($params);
    }

    /**
     * Check if a widget key is registered.
     */
    public function has(string $widgetKey): bool
    {
        return isset($this->widgets[$widgetKey]);
    }

    /**
     * Return the raw registry map (useful for debugging).
     *
     * @return array<string, class-string<WidgetInterface>>
     */
    public function all(): array
    {
        return $this->widgets;
    }
}
