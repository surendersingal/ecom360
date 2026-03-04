<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Models\CustomerProfile;

/**
 * Maps anonymous session IDs to known customer identities.
 *
 * When a customer_identifier is provided (e.g. on login, checkout, or form submit),
 * this service either creates a new CustomerProfile or merges the session and any
 * custom attributes into the existing profile — all scoped to the tenant.
 */
final class IdentityResolutionService
{
    /**
     * Resolve (or create) a customer identity and link the current session.
     *
     * @param  string       $tenantId
     * @param  string       $sessionId
     * @param  array{type: string, value: string}|null $identifier  e.g. ['type' => 'email', 'value' => 'john@example.com']
     * @param  array<string,mixed>|null                $customAttributes  Arbitrary data to merge into the profile.
     */
    public function resolveIdentity(
        int|string $tenantId,
        string $sessionId,
        ?array $identifier,
        ?array $customAttributes,
    ): void {
        // Nothing to resolve if we have no identifier.
        if ($identifier === null || !isset($identifier['type'], $identifier['value'])) {
            return;
        }

        $identifierType  = $identifier['type'];
        $identifierValue = $identifier['value'];

        // Atomic lock prevents duplicate profile creation under concurrency.
        $lockKey = "id_resolve:{$tenantId}:{$identifierValue}";

        Cache::lock($lockKey, 10)->block(5, function () use ($tenantId, $sessionId, $identifierType, $identifierValue, $customAttributes) {
            $profile = CustomerProfile::query()
                ->where('tenant_id', $tenantId)
                ->where('identifier_value', $identifierValue)
                ->first();

            if ($profile === null) {
                // ---------------------------------------------------------------
                //  New customer — create the profile with the first session.
                // ---------------------------------------------------------------
                CustomerProfile::create([
                    'tenant_id'         => $tenantId,
                    'identifier_type'   => $identifierType,
                    'identifier_value'  => $identifierValue,
                    'known_sessions'    => [$sessionId],
                    'custom_attributes' => $customAttributes ?? [],
                ]);

                Log::info("[IdentityResolution] Created new profile for [{$identifierValue}] in tenant [{$tenantId}].");

                return;
            }

            // ---------------------------------------------------------------
            //  Existing customer — add session (no duplicates) & merge attrs.
            // ---------------------------------------------------------------

            // MongoDB $addToSet guarantees no duplicate session_ids.
            $profile->push('known_sessions', [$sessionId], true);

            // Merge new custom attributes into existing ones (new keys win).
            if ($customAttributes !== null && $customAttributes !== []) {
                $merged = array_merge(
                    $profile->custom_attributes ?? [],
                    $customAttributes,
                );

                $profile->update(['custom_attributes' => $merged]);
            }

            Log::info("[IdentityResolution] Linked session [{$sessionId}] to profile [{$identifierValue}] in tenant [{$tenantId}].");
        });
    }
}
