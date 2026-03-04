<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Every dashboard widget across all modules must implement this interface.
 *
 * The WidgetRegistry discovers widgets by this contract, enabling the
 * frontend to build fully dynamic, drag-and-drop dashboard layouts.
 */
interface WidgetInterface
{
    /**
     * A human-readable display name for the widget.
     *
     * Example: "Revenue Chart"
     */
    public function getName(): string;

    /**
     * Metadata the frontend needs to render the widget shell
     * (icon, default size, category, description, etc.).
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * Resolve live data for this widget.
     *
     * @param  array<string, mixed>  $params  Filters / date-range / tenant context.
     * @return array<string, mixed>
     */
    public function resolveData(array $params): array;
}
