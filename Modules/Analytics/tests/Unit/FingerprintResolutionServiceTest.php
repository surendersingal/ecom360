<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests\Unit;

use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Services\FingerprintResolutionService;
use Tests\TestCase;

/**
 * Unit tests for FingerprintResolutionService.
 *
 * Covers:
 *  1. Null/empty fingerprint returns null (no-op).
 *  2. New fingerprint creates an anonymous profile.
 *  3. Known fingerprint links new session to existing profile.
 *  4. Session already linked — fingerprint is attached to that profile.
 *  5. Profile merge absorbs sessions + fingerprints.
 */
final class FingerprintResolutionServiceTest extends TestCase
{
    private const string TENANT = 'fp_test_tenant';

    private FingerprintResolutionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FingerprintResolutionService::class);
        CustomerProfile::where('tenant_id', self::TENANT)->delete();
    }

    protected function tearDown(): void
    {
        CustomerProfile::where('tenant_id', self::TENANT)->delete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  1. Null / empty fingerprint → no-op
    // ------------------------------------------------------------------

    public function test_null_fingerprint_returns_null(): void
    {
        $result = $this->service->resolve(self::TENANT, 'sess_1', null);
        $this->assertNull($result);
    }

    public function test_empty_fingerprint_returns_null(): void
    {
        $result = $this->service->resolve(self::TENANT, 'sess_1', '');
        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    //  2. Brand-new fingerprint → creates anonymous profile
    // ------------------------------------------------------------------

    public function test_new_fingerprint_creates_anonymous_profile(): void
    {
        $fp = hash('sha256', 'device_abc');

        $profile = $this->service->resolve(self::TENANT, 'sess_new', $fp);

        $this->assertNotNull($profile);
        $this->assertSame('anonymous', $profile->identifier_type);
        $this->assertSame('fp:' . $fp, $profile->identifier_value);
        $this->assertContains('sess_new', $profile->known_sessions);
        $this->assertContains($fp, $profile->device_fingerprints);
        $this->assertSame(self::TENANT, $profile->tenant_id);
    }

    // ------------------------------------------------------------------
    //  3. Returning visitor — same fingerprint, new session
    // ------------------------------------------------------------------

    public function test_known_fingerprint_links_new_session(): void
    {
        $fp = hash('sha256', 'device_returning');

        // First visit creates the profile.
        $this->service->resolve(self::TENANT, 'sess_first', $fp);

        // Second visit with a different session but same fingerprint.
        $profile = $this->service->resolve(self::TENANT, 'sess_second', $fp);

        $this->assertNotNull($profile);

        // Refresh from DB to see the updated known_sessions.
        $profile->refresh();

        $this->assertContains('sess_first', $profile->known_sessions);
        $this->assertContains('sess_second', $profile->known_sessions);

        // Should still be the same profile (only 1 for this tenant).
        $count = CustomerProfile::where('tenant_id', self::TENANT)->count();
        $this->assertSame(1, $count);
    }

    // ------------------------------------------------------------------
    //  4. Session already linked (identity resolution) — attach fingerprint
    // ------------------------------------------------------------------

    public function test_existing_session_profile_gets_fingerprint_attached(): void
    {
        // Simulate identity resolution creating a profile first (with session, no fingerprint).
        CustomerProfile::create([
            'tenant_id'           => self::TENANT,
            'identifier_type'     => 'email',
            'identifier_value'    => 'jane@example.com',
            'known_sessions'      => ['sess_known'],
            'device_fingerprints' => [],
            'custom_attributes'   => [],
        ]);

        $fp = hash('sha256', 'device_jane');

        // Now fingerprint resolution runs with that same session.
        $profile = $this->service->resolve(self::TENANT, 'sess_known', $fp);

        $this->assertNotNull($profile);
        $profile->refresh();

        $this->assertSame('jane@example.com', $profile->identifier_value);
        $this->assertContains($fp, $profile->device_fingerprints);
    }

    // ------------------------------------------------------------------
    //  5. Profile merge
    // ------------------------------------------------------------------

    public function test_merge_profiles_absorbs_sessions_and_fingerprints(): void
    {
        $target = CustomerProfile::create([
            'tenant_id'           => self::TENANT,
            'identifier_type'     => 'email',
            'identifier_value'    => 'primary@example.com',
            'known_sessions'      => ['sess_a'],
            'device_fingerprints' => ['fp_a'],
            'custom_attributes'   => ['tier' => 'gold'],
        ]);

        $source = CustomerProfile::create([
            'tenant_id'           => self::TENANT,
            'identifier_type'     => 'anonymous',
            'identifier_value'    => 'fp:fp_b',
            'known_sessions'      => ['sess_b', 'sess_c'],
            'device_fingerprints' => ['fp_b'],
            'custom_attributes'   => ['referral' => 'organic'],
        ]);

        $this->service->mergeProfiles($target, $source);

        $target->refresh();

        // Target should have all sessions.
        $this->assertContains('sess_a', $target->known_sessions);
        $this->assertContains('sess_b', $target->known_sessions);
        $this->assertContains('sess_c', $target->known_sessions);

        // Target should have all fingerprints.
        $this->assertContains('fp_a', $target->device_fingerprints);
        $this->assertContains('fp_b', $target->device_fingerprints);

        // Target custom_attributes should have merged (target wins on conflict).
        $this->assertSame('gold', $target->custom_attributes['tier']);
        $this->assertSame('organic', $target->custom_attributes['referral']);

        // Source should be deleted.
        $this->assertNull(CustomerProfile::find($source->_id));
    }

    // ------------------------------------------------------------------
    //  6. Idempotency — same session + fingerprint doesn't create duplicates
    // ------------------------------------------------------------------

    public function test_same_fingerprint_same_session_is_idempotent(): void
    {
        $fp = hash('sha256', 'device_idem');

        $this->service->resolve(self::TENANT, 'sess_idem', $fp);
        $this->service->resolve(self::TENANT, 'sess_idem', $fp);

        $count = CustomerProfile::where('tenant_id', self::TENANT)->count();
        $this->assertSame(1, $count);

        $profile = CustomerProfile::where('tenant_id', self::TENANT)->first();

        // known_sessions should not have duplicates (MongoDB $addToSet).
        $sessions = $profile->known_sessions;
        $this->assertCount(1, array_unique($sessions));
    }
}
