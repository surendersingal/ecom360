<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Requests\StoreIngestionRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Services\TrackingService;

/**
 * Public-facing ingestion endpoint for the Analytics module.
 *
 * Accepts tracking payloads from the frontend SDK (page views, product
 * views, cart updates, etc.) and routes them through:
 *
 *  1. Device Fingerprint Resolution (anonymous user recognition)
 *  2. Identity Resolution (email / phone → known customer)
 *  3. Attribution enrichment (campaign / AI search source)
 *  4. MongoDB persistence
 *
 * The endpoint is designed for high throughput — validation happens in
 * the FormRequest and the heavy lifting is delegated to TrackingService.
 */
final class IngestionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TrackingService $trackingService,
    ) {}

    /**
     * POST /api/v1/analytics/ingest
     *
     * Accept a tracking event payload, persist it, and return the
     * created tracking event ID.
     */
    public function __invoke(StoreIngestionRequest $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;

        if ($tenantId === null) {
            return $this->errorResponse('Tenant context is required.', 403);
        }

        $payload = $request->validated('payload');

        try {
            $trackingEvent = $this->trackingService->logEvent((string) $tenantId, $payload);

            return $this->successResponse([
                'tracking_event_id' => (string) $trackingEvent->_id,
                'event_type'        => $trackingEvent->event_type,
                'session_id'        => $trackingEvent->session_id,
            ], 'Event ingested successfully.', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('[Analytics] Ingestion failed: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'payload'   => $payload,
            ]);

            return $this->errorResponse('Ingestion failed.', 500);
        }
    }
}
