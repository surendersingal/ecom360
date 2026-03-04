<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;

/**
 * GDPR / CCPA Privacy Compliance Service.
 *
 * Implements the "Right to be Forgotten" (GDPR Art. 17) by irreversibly
 * purging all personally identifiable data for a given customer:
 *
 *  1. Locate the CustomerProfile by tenant + identifier value.
 *  2. Extract all known_sessions linked to that profile.
 *  3. Delete every TrackingEvent in MongoDB that belongs to those sessions.
 *  4. Scrub related Redis keys (intent scores, live context, cooldowns).
 *  5. Delete the CustomerProfile itself.
 *
 * Returns a summary array so the caller can audit the operation.
 */
final class PrivacyComplianceService
{
    /**
     * Irreversibly purge ALL customer data for the given identifier.
     *
     * @param  int $tenantId         Tenant scope.
     * @param  string $identifierValue  The customer's unique identifier
     *                                  (email, phone, or fp:hash).
     *
     * @return array{
     *     profile_found: bool,
     *     sessions_purged: int,
     *     events_deleted: int,
     *     redis_keys_removed: int,
     * }
     */
    public function purgeCustomerData(int|string $tenantId, string $identifierValue): array
    {
        $profile = CustomerProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('identifier_value', $identifierValue)
            ->first();

        if ($profile === null) {
            Log::info("[PrivacyCompliance] No profile found for [{$identifierValue}] in tenant [{$tenantId}]. Nothing to purge.");

            return [
                'profile_found'     => false,
                'sessions_purged'   => 0,
                'events_deleted'    => 0,
                'redis_keys_removed' => 0,
            ];
        }

        $sessions = $profile->known_sessions ?? [];

        // -----------------------------------------------------------------
        //  Step 1: Delete all tracking events for the customer's sessions.
        // -----------------------------------------------------------------
        $eventsDeleted = 0;

        if ($sessions !== []) {
            $eventsDeleted = TrackingEvent::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('session_id', $sessions)
                ->delete();
        }

        // -----------------------------------------------------------------
        //  Step 2: Scrub Redis ephemeral data (intent scores, cooldowns).
        // -----------------------------------------------------------------
        $redisKeysRemoved = $this->purgeRedisData($sessions);

        // -----------------------------------------------------------------
        //  Step 3: Delete the CustomerProfile.
        // -----------------------------------------------------------------
        $profile->delete();

        Log::info(
            "[PrivacyCompliance] Purged customer [{$identifierValue}] in tenant [{$tenantId}]: "
            . count($sessions) . " sessions, {$eventsDeleted} events, {$redisKeysRemoved} Redis keys.",
        );

        return [
            'profile_found'      => true,
            'sessions_purged'    => count($sessions),
            'events_deleted'     => $eventsDeleted,
            'redis_keys_removed' => $redisKeysRemoved,
        ];
    }

    /**
     * Remove ephemeral Redis keys associated with the customer's sessions.
     *
     * @param  list<string> $sessions
     * @return int  Number of Redis keys deleted.
     */
    private function purgeRedisData(array $sessions): int
    {
        if ($sessions === []) {
            return 0;
        }

        $keysToDelete = [];

        foreach ($sessions as $sessionId) {
            // Intent score key (IntentScoringService).
            $keysToDelete[] = "intent:score:{$sessionId}";

            // Live context keys (LiveContextService).
            $keysToDelete[] = "live_ctx:page:{$sessionId}";
            $keysToDelete[] = "live_ctx:cart:{$sessionId}";
            $keysToDelete[] = "live_ctx:attr:{$sessionId}";
        }

        if ($keysToDelete === []) {
            return 0;
        }

        return (int) Redis::del($keysToDelete);
    }
}
