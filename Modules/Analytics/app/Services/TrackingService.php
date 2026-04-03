<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MongoDB\Laravel\Connection;
use Modules\Analytics\Events\DashboardDataUpdated;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;

/**
 * Core analytics service — data ingestion, identity resolution,
 * attribution & aggregation.
 *
 * All MongoDB queries are scoped to a tenant_id for strict multi-tenant isolation.
 */
final class TrackingService
{
    public function __construct(
        private readonly IdentityResolutionService $identityService,
        private readonly FingerprintResolutionService $fingerprintService,
        private readonly LiveContextService $liveContextService,
        private readonly AttributionService $attributionService,
        private readonly GeoIpService $geoIpService,
    ) {}

    // ------------------------------------------------------------------
    //  Data Ingestion
    // ------------------------------------------------------------------

    /**
     * Validate and persist a tracking event to MongoDB.
     *
     * Before saving:
     *  1. Identity resolution links the session to a known customer.
     *  2. For purchase events, attribution data (campaign / ai_search)
     *     from the LiveContextService is appended to the document.
     *
     * @param  string              $tenantId
     * @param  array<string,mixed> $payload
     *
     * @throws ValidationException
     */
    public function logEvent(int|string $tenantId, array $payload): TrackingEvent
    {
        $validated = Validator::make($payload, [
            'session_id'           => ['required', 'string', 'max:128'],
            'event_type'           => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'url'                  => ['required', 'string', 'url', 'max:2048'],
            'metadata'             => ['sometimes', 'array'],
            'custom_data'          => ['nullable', 'array'],
            'customer_identifier'  => ['nullable', 'array'],
            'device_fingerprint'   => ['nullable', 'string', 'max:128'],
            'ip_address'           => ['sometimes', 'string', 'ip'],
            'user_agent'           => ['sometimes', 'string', 'max:512'],
        ])->validate();

        // --- Fingerprint Resolution (link fingerprint → profile) ---------
        $this->fingerprintService->resolve(
            tenantId:        $tenantId,
            sessionId:       $validated['session_id'],
            fingerprintHash: $validated['device_fingerprint'] ?? null,
        );

        // --- Identity Resolution (link session → known customer) ---------
        $this->identityService->resolveIdentity(
            tenantId:         $tenantId,
            sessionId:        $validated['session_id'],
            identifier:       $validated['customer_identifier'] ?? null,
            customAttributes: $validated['custom_data'] ?? null,
        );

        // --- Attribution (for purchase events) ---------------------------
        $metadata = $validated['metadata'] ?? [];

        if ($validated['event_type'] === 'purchase') {
            // Redis-based last-click attribution (24h window).
            $attribution = $this->liveContextService->getAttribution($validated['session_id']);

            if ($attribution !== null) {
                $metadata['attribution'] = $attribution;

                Log::debug(
                    "[Analytics] Purchase attributed to {$attribution['source']}:{$attribution['source_id']} "
                    . "for session [{$validated['session_id']}] in tenant [{$tenantId}].",
                );
            }

            // MongoDB-based multi-touch attribution (full session history).
            $multiTouch = $this->attributionService->resolveConversionSource(
                $tenantId,
                $validated['session_id'],
            );

            if ($multiTouch['touch_count'] > 0) {
                $metadata['multi_touch_attribution'] = $multiTouch;
            }
        }

        // --- Persist the tracking event ----------------------------------
        // Enrich with GeoIP + device data
        $geoData = null;
        if (!empty($validated['ip_address'])) {
            $geoData = $this->geoIpService->resolve($validated['ip_address']);
        }

        $deviceData = null;
        if (!empty($validated['user_agent'])) {
            $deviceData = $this->geoIpService->parseUserAgent($validated['user_agent']);
        }

        if ($geoData) {
            $metadata['geo'] = $geoData;
        }
        if ($deviceData) {
            $metadata['device'] = $deviceData;
        }

        $eventData = [
            'tenant_id'   => $tenantId,
            'session_id'  => $validated['session_id'],
            'event_type'  => $validated['event_type'],
            'url'         => $validated['url'],
            'metadata'    => $metadata,
            'custom_data' => $validated['custom_data'] ?? [],
            'ip_address'  => $validated['ip_address'] ?? '',
            'user_agent'  => $validated['user_agent'] ?? '',
        ];

        // Allow client-supplied timestamp for event ordering within sessions
        if (! empty($validated['timestamp'])) {
            $eventData['created_at'] = \Carbon\Carbon::parse($validated['timestamp']);
        }

        try {
            $event = TrackingEvent::create($eventData);
        } catch (\Throwable $e) {
            Log::error('[Analytics] Failed to persist tracking event: ' . $e->getMessage());
            throw $e; // Re-throw — event ingestion failure should be reported to the caller
        }

        Log::debug("[Analytics] Tracked {$validated['event_type']} for tenant {$tenantId}");

        // --- Broadcast dashboard update over WebSockets ------------------
        try {
            broadcast(new DashboardDataUpdated(
                tenantId:  $tenantId,
                eventType: $event->event_type,
                sessionId: $event->session_id,
                timestamp: now()->toIso8601String(),
            ));
        } catch (\Throwable $e) {
            // Broadcasting failure must never break the ingestion pipeline.
            Log::warning('[Analytics] Dashboard broadcast failed: ' . $e->getMessage());
        }

        return $event;
    }

    // ------------------------------------------------------------------
    //  Aggregation
    // ------------------------------------------------------------------

    /**
     * Aggregate traffic metrics for a tenant over a given date range.
     *
     * Uses MongoDB's Aggregation Pipeline so all heavy lifting happens
     * inside the database engine rather than in PHP.
     *
     * @param  int $tenantId
     * @param  string $dateRange  A human-readable range: '7d', '30d', '90d', or ISO "Y-m-d|Y-m-d"
     *
     * @return array{
     *     unique_sessions: int,
     *     total_events: int,
     *     event_type_breakdown: array<string, int>,
     *     date_from: string,
     *     date_to: string,
     * }
     */
    public function aggregateTraffic(int|string $tenantId, string $dateRange = '30d'): array
    {
        try {
            [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

            /** @var Connection $mongo */
            $mongo = app('db')->connection('mongodb');

            $collection = $mongo->getCollection('tracking_events');

            // ------------------------------------------------------------------
            // Pipeline: filter → facet (unique sessions + event breakdown)
            // ------------------------------------------------------------------
            $pipeline = [
                // Stage 1: Match tenant + date window.
                [
                    '$match' => [
                        'tenant_id'  => $tenantId,
                        'created_at' => [
                            '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                            '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                        ],
                    ],
                ],

                // Stage 2: Facet — run two aggregations in parallel.
                [
                    '$facet' => [
                        'unique_sessions' => [
                            ['$group' => ['_id' => '$session_id']],
                            ['$count' => 'count'],
                        ],
                        'event_breakdown' => [
                            ['$group' => ['_id' => '$event_type', 'count' => ['$sum' => 1]]],
                        ],
                        'total' => [
                            ['$count' => 'count'],
                        ],
                    ],
                ],
            ];

            $results = iterator_to_array($collection->aggregate($pipeline, ['maxTimeMS' => 30000]));
            $facets  = $results[0] ?? [];

            $uniqueSessions = $facets['unique_sessions'][0]['count'] ?? 0;
            $totalEvents    = $facets['total'][0]['count'] ?? 0;

            $breakdown = [];
            foreach (($facets['event_breakdown'] ?? []) as $row) {
                $breakdown[$row['_id']] = $row['count'];
            }

            return [
                'unique_sessions'     => (int) $uniqueSessions,
                'total_events'        => (int) $totalEvents,
                'event_type_breakdown' => $breakdown,
                'date_from'           => $dateFrom->toDateString(),
                'date_to'             => $dateTo->toDateString(),
            ];
        } catch (\Throwable $e) {
            [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);
            Log::warning('[Analytics] aggregateTraffic failed: ' . $e->getMessage());
            return [
                'unique_sessions'      => 0,
                'total_events'         => 0,
                'event_type_breakdown' => [],
                'date_from'            => $dateFrom->toDateString(),
                'date_to'              => $dateTo->toDateString(),
            ];
        }
    }

    // ------------------------------------------------------------------
    //  Customer Journey
    // ------------------------------------------------------------------

    /**
     * Reconstruct the full chronological journey of a known customer.
     *
     * Step A: Find the CustomerProfile by tenant + identifier.
     * Step B: Extract all known_sessions.
     * Step C: Query TrackingEvent where session_id $in known_sessions,
     *         ordered by created_at ASC — from first anonymous page view
     *         through to checkout.
     *
     * @param  int $tenantId
     * @param  string $identifierValue  e.g. 'john@example.com'
     *
     * @return array{
     *     profile: array<string,mixed>|null,
     *     journey: list<array<string,mixed>>,
     * }
     */
    public function getCustomerJourney(int|string $tenantId, string $identifierValue): array
    {
        try {
            // Step A: Locate the profile.
            $profile = CustomerProfile::query()
                ->where('tenant_id', $tenantId)
                ->where('identifier_value', $identifierValue)
                ->first();

            if ($profile === null) {
                return [
                    'profile' => null,
                    'journey' => [],
                ];
            }

            $knownSessions = $profile->known_sessions ?? [];

            if ($knownSessions === []) {
                return [
                    'profile' => $profile->toArray(),
                    'journey' => [],
                ];
            }

            // Step C: Pull every event across all linked sessions.
            $events = TrackingEvent::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('session_id', $knownSessions)
                ->orderBy('created_at', 'asc')
                ->get()
                ->toArray();

            return [
                'profile' => $profile->toArray(),
                'journey' => $events,
            ];
        } catch (\Throwable $e) {
            Log::warning('[Analytics] getCustomerJourney failed: ' . $e->getMessage());
            return ['profile' => null, 'journey' => []];
        }
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function parseDateRange(string $range): array
    {
        // Shorthand: "7d", "30d", "90d"
        if (preg_match('/^(\d+)d$/', $range, $m)) {
            $days = (int) $m[1];
            return [
                CarbonImmutable::now()->subDays($days)->startOfDay(),
                CarbonImmutable::now()->endOfDay(),
            ];
        }

        // Explicit range: "2026-01-01|2026-01-31"
        if (str_contains($range, '|')) {
            [$from, $to] = explode('|', $range, 2);
            return [
                CarbonImmutable::parse($from)->startOfDay(),
                CarbonImmutable::parse($to)->endOfDay(),
            ];
        }

        // Fallback: last 30 days.
        return [
            CarbonImmutable::now()->subDays(30)->startOfDay(),
            CarbonImmutable::now()->endOfDay(),
        ];
    }
}
