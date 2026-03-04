<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Models\CustomerProfile;

/**
 * Resolves returning anonymous users via device fingerprinting.
 *
 * A device fingerprint is a hash representing a unique browser/device
 * combination (screen size, installed fonts, WebGL renderer, etc.).
 * When the frontend sends this hash with every tracking event, this
 * service can:
 *
 *  1. Find an existing CustomerProfile that already has the same
 *     fingerprint and link the current session to it.
 *  2. Add the fingerprint to an existing profile (found by session)
 *     so future anonymous visits can be recognized.
 *  3. Create a brand-new anonymous profile seeded with the fingerprint
 *     when no match exists at all.
 *
 * This runs BEFORE identity resolution so anonymous sessions are merged
 * FIRST, then the customer_identifier (email/phone) upgrades the profile.
 */
final class FingerprintResolutionService
{
    /**
     * Attempt to recognize the user by device fingerprint and link the
     * current session to the matching (or newly created) profile.
     *
     * @param  string      $tenantId         Tenant scope.
     * @param  string      $sessionId        Current session ID.
     * @param  string|null $fingerprintHash  SHA-256 device fingerprint (nullable — no-op if absent).
     *
     * @return CustomerProfile|null  The matched/created profile, or null if no fingerprint was provided.
     */
    public function resolve(
        int|string $tenantId,
        string $sessionId,
        ?string $fingerprintHash,
    ): ?CustomerProfile {
        if ($fingerprintHash === null || $fingerprintHash === '') {
            return null;
        }

        // Atomic lock prevents duplicate profile creation under concurrency.
        // In production (CACHE_STORE=redis), this is a true distributed lock.
        $lockKey = "fp_resolve:{$tenantId}:{$fingerprintHash}";

        return Cache::lock($lockKey, 10)->block(5, function () use ($tenantId, $sessionId, $fingerprintHash) {
            // -----------------------------------------------------------------
            //  Step 1: Look for an existing profile that owns this fingerprint.
            // -----------------------------------------------------------------
            $profile = CustomerProfile::query()
                ->where('tenant_id', $tenantId)
                ->where('device_fingerprints', $fingerprintHash)
                ->first();

            if ($profile !== null) {
                // Link the new session (no duplicates thanks to $addToSet).
                $profile->push('known_sessions', [$sessionId], true);

                Log::info(
                    "[FingerprintResolution] Recognized returning visitor via fingerprint [{$fingerprintHash}] "
                    . "- linked session [{$sessionId}] to profile [{$profile->identifier_value}] "
                    . "in tenant [{$tenantId}].",
                );

                return $profile;
            }

            // -----------------------------------------------------------------
            //  Step 2: Check if the session is already linked to a profile
            //  (e.g. from a prior identity resolution). If so, attach the
            //  fingerprint to that existing profile.
            // -----------------------------------------------------------------
            $existingBySession = CustomerProfile::query()
                ->where('tenant_id', $tenantId)
                ->where('known_sessions', $sessionId)
                ->first();

            if ($existingBySession !== null) {
                $existingBySession->push('device_fingerprints', [$fingerprintHash], true);

                Log::info(
                    "[FingerprintResolution] Added fingerprint [{$fingerprintHash}] to existing profile "
                    . "[{$existingBySession->identifier_value}] in tenant [{$tenantId}].",
                );

                return $existingBySession;
            }

            // -----------------------------------------------------------------
            //  Step 3: Completely new anonymous visitor — create a stub profile
            //  seeded with the fingerprint and session.
            // -----------------------------------------------------------------
            $newProfile = CustomerProfile::create([
                'tenant_id'           => $tenantId,
                'identifier_type'     => 'anonymous',
                'identifier_value'    => 'fp:' . $fingerprintHash,
                'known_sessions'      => [$sessionId],
                'device_fingerprints' => [$fingerprintHash],
                'custom_attributes'   => [],
            ]);

            Log::info(
                "[FingerprintResolution] Created new anonymous profile for fingerprint [{$fingerprintHash}] "
                . "with session [{$sessionId}] in tenant [{$tenantId}].",
            );

            return $newProfile;
        });
    }

    /**
     * Merge two CustomerProfiles when fingerprint resolution and identity
     * resolution independently create profiles that are later discovered
     * to belong to the same person.
     *
     * The "target" profile survives; the "source" profile is deleted.
     * All sessions and fingerprints from the source are absorbed.
     */
    public function mergeProfiles(CustomerProfile $target, CustomerProfile $source): void
    {
        $sourceSessions     = $source->known_sessions ?? [];
        $sourceFingerprints = $source->device_fingerprints ?? [];

        // Absorb sessions.
        foreach ($sourceSessions as $sid) {
            $target->push('known_sessions', [$sid], true);
        }

        // Absorb fingerprints.
        foreach ($sourceFingerprints as $fp) {
            $target->push('device_fingerprints', [$fp], true);
        }

        // Merge custom attributes (target wins on key conflicts).
        $mergedAttrs = array_merge(
            $source->custom_attributes ?? [],
            $target->custom_attributes ?? [],
        );
        $target->update(['custom_attributes' => $mergedAttrs]);

        Log::info(
            "[FingerprintResolution] Merged profile [{$source->_id}] into [{$target->_id}]. "
            . 'Absorbed ' . count($sourceSessions) . ' sessions and '
            . count($sourceFingerprints) . ' fingerprints.',
        );

        $source->delete();
    }
}
