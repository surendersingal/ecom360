<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Http\Requests\PublicBatchTrackingRequest;
use Modules\Analytics\Http\Requests\PublicTrackingRequest;
use Modules\Analytics\Services\TrackingService;

/**
 * Public-facing ingestion controller for the Store JS SDK.
 *
 * Authenticates via X-Ecom360-Key header (ValidateTrackingApiKey middleware)
 * instead of Sanctum tokens, enabling cross-origin storefront tracking.
 *
 * Supports:
 *  - Single event ingestion  (POST /collect)
 *  - Batched event ingestion (POST /collect/batch)
 *  - CORS preflight          (OPTIONS /collect)
 */
final class PublicIngestionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TrackingService $trackingService,
    ) {}

    /**
     * POST /api/v1/collect
     *
     * Ingest a single tracking event from the storefront SDK.
     */
    public function collect(PublicTrackingRequest $request): JsonResponse
    {
        $tenantId = (string) $request->input('_tenant_id');
        $validated = $request->validated();

        // Enrich with server-side data the client can't reliably provide.
        $validated['ip_address'] = $validated['ip_address'] ?? $request->ip();
        $validated['user_agent'] = $validated['user_agent'] ?? $request->userAgent() ?? '';

        // Move extended fields into metadata.
        $metadata = $validated['metadata'] ?? [];
        foreach (['referrer', 'screen_resolution', 'timezone', 'language', 'page_title', 'utm'] as $field) {
            if (isset($validated[$field])) {
                $metadata[$field] = $validated[$field];
                unset($validated[$field]);
            }
        }
        $validated['metadata'] = $metadata;

        try {
            $event = $this->trackingService->logEvent($tenantId, $validated);

            return $this->successResponse([
                'id'         => (string) $event->_id,
                'event_type' => $event->event_type,
                'session_id' => $event->session_id,
                'ts'         => $event->created_at?->toIso8601String(),
            ], 'OK', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('[Analytics] Public ingestion failed: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'event_type' => $validated['event_type'] ?? 'unknown',
            ]);

            return $this->errorResponse('Ingestion failed.', 500);
        }
    }

    /**
     * POST /api/v1/collect/batch
     *
     * Ingest a batch of tracking events (max 50 per request).
     * Returns the count of successfully ingested events.
     */
    public function batch(PublicBatchTrackingRequest $request): JsonResponse
    {
        $tenantId = (string) $request->input('_tenant_id');
        $events = $request->validated('events');

        $ingested = 0;
        $errors = [];

        foreach ($events as $index => $eventData) {
            $eventData['ip_address'] = $eventData['ip_address'] ?? $request->ip();
            $eventData['user_agent'] = $eventData['user_agent'] ?? $request->userAgent() ?? '';

            // Move extended fields into metadata.
            $metadata = $eventData['metadata'] ?? [];
            foreach (['referrer', 'screen_resolution', 'timezone', 'language', 'page_title', 'utm'] as $field) {
                if (isset($eventData[$field])) {
                    $metadata[$field] = $eventData[$field];
                    unset($eventData[$field]);
                }
            }
            $eventData['metadata'] = $metadata;

            try {
                $this->trackingService->logEvent($tenantId, $eventData);
                $ingested++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'index' => $index,
                    'event_type' => $eventData['event_type'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];

                Log::warning("[Analytics] Batch event #{$index} failed: " . $e->getMessage(), [
                    'tenant_id' => $tenantId,
                ]);
            }
        }

        $status = $errors === [] ? 201 : ($ingested > 0 ? 207 : 422);

        return response()->json([
            'success'  => $ingested > 0,
            'message'  => "{$ingested}/" . count($events) . " events ingested.",
            'data'     => [
                'ingested'    => $ingested,
                'total'       => count($events),
                'errors'      => $errors,
            ],
        ], $status);
    }

    /**
     * GET /api/v1/interventions/poll
     *
     * Returns pending behavioral interventions for the given session.
     * The tracker JS polls this every 15 seconds as a fallback when WebSockets are unavailable.
     */
    public function interventionPoll(Request $request): JsonResponse
    {
        // Stub: return empty interventions array. Future: query rules engine.
        return response()->json([
            'success' => true,
            'data'    => [],
        ]);
    }

    /**
     * OPTIONS /api/v1/collect (CORS preflight).
     */
    public function preflight(): JsonResponse
    {
        $origin = request()->header('Origin', '*');

        return response()->json(null, 204, [
            'Access-Control-Allow-Origin'      => $origin,
            'Access-Control-Allow-Methods'     => 'POST, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Content-Type, X-Ecom360-Key, X-Requested-With',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age'           => '86400',
        ]);
    }
}
