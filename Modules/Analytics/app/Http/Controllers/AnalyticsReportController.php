<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Analytics\Http\Requests\GetAnalyticsReportRequest;

/**
 * Serves aggregated Analytics data to the frontend dashboard.
 *
 * Uses the Core WidgetRegistry to resolve each requested widget,
 * passing tenant context and date range so data is scoped correctly.
 */
final class AnalyticsReportController extends Controller
{
    public function __construct(
        private readonly WidgetRegistry $widgetRegistry,
    ) {}

    /**
     * GET /api/v1/analytics/report
     *
     * Accepts an array of widget_keys and a date_range, resolves live
     * data for each widget, and returns a structured JSON response.
     */
    public function __invoke(GetAnalyticsReportRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $tenantId  = (string) $user->tenant_id;
        $dateRange = $request->validated('date_range');
        $widgetKeys = $request->validated('widget_keys');

        $widgets = [];
        $errors  = [];

        foreach ($widgetKeys as $key) {
            if (!$this->widgetRegistry->has($key)) {
                $errors[$key] = 'Widget not registered.';
                continue;
            }

            $widgets[$key] = $this->widgetRegistry->resolveWidget($key, [
                'tenant_id'  => $tenantId,
                'date_range' => $dateRange,
            ]);
        }

        return $this->successResponse([
            'tenant_id'  => $tenantId,
            'date_range' => $dateRange,
            'widgets'    => $widgets,
            'errors'     => $errors,
        ]);
    }
}
