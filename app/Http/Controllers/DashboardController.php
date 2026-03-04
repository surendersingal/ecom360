<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DashboardLayout;
use App\Models\User;
use App\Services\WidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly WidgetRegistry $widgetRegistry,
    ) {}

    /**
     * GET /api/dashboard/widgets
     *
     * Returns the authenticated user's saved dashboard layout merged with
     * live data from each widget via the WidgetRegistry.
     */
    public function getWidgets(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        // Fetch the user's default layout (or the first available).
        $layout = DashboardLayout::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->first();

        // If no default layout exists, return all available widgets with empty positioning.
        if ($layout === null) {
            return $this->successResponse(
                data: [
                    'layout'           => null,
                    'available_widgets' => $this->widgetRegistry->getAvailableWidgets(),
                ],
                message: 'No saved layout found. Returning available widgets.',
            );
        }

        // Merge each layout tile with live widget data.
        $tiles = collect($layout->layout_data)->map(function (array $tile) use ($request): array {
            $widgetKey = $tile['widget_key'] ?? '';

            if (!$this->widgetRegistry->has($widgetKey)) {
                $tile['data']  = [];
                $tile['error'] = "Widget [{$widgetKey}] is no longer available.";

                return $tile;
            }

            $tile['data'] = $this->widgetRegistry->resolveWidget(
                widgetKey: $widgetKey,
                params: $request->query(), // forward any global filters
            );

            return $tile;
        })->all();

        return $this->successResponse(
            data: [
                'layout' => [
                    'id'          => $layout->id,
                    'name'        => $layout->name,
                    'is_default'  => $layout->is_default,
                    'tiles'       => $tiles,
                ],
                'available_widgets' => $this->widgetRegistry->getAvailableWidgets(),
            ],
            message: 'Dashboard loaded successfully.',
        );
    }
}
