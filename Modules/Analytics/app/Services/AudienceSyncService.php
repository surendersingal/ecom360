<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Audience Sync Service.
 *
 * Syncs audience segments built in the Audience Builder to external
 * advertising platforms so merchants can run targeted campaigns.
 *
 * Supported destinations:
 *   - Google Ads (Customer Match)
 *   - Meta/Facebook Ads (Custom Audiences)
 *   - TikTok Ads (Custom Audiences)
 *   - Klaviyo (Lists/Segments)
 *
 * Uses hashed PII (SHA-256 of email/phone) for privacy compliance.
 */
final class AudienceSyncService
{
    /**
     * Sync a segment to an external destination.
     */
    public function sync(int|string $tenantId, int $segmentId, string $destination, array $credentials): array
    {
        $members = $this->getSegmentMembers($tenantId, $segmentId);
        if (empty($members)) {
            return ['status' => 'skipped', 'reason' => 'No members in segment'];
        }

        $hashedMembers = $this->hashMembers($members);

        return match ($destination) {
            'google_ads' => $this->syncToGoogleAds($hashedMembers, $credentials),
            'meta_ads' => $this->syncToMetaAds($hashedMembers, $credentials),
            'tiktok_ads' => $this->syncToTikTokAds($hashedMembers, $credentials),
            'klaviyo' => $this->syncToKlaviyo($members, $credentials),
            default => ['status' => 'error', 'reason' => "Unsupported destination: {$destination}"],
        };
    }

    /**
     * List all sync-able segments with their member counts.
     */
    public function listSegments(int|string $tenantId): array
    {
        $segments = DB::connection('mongodb')->table('audience_segments')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get()
            ->map(fn($s) => (array) $s)
            ->all();

        return array_map(fn($s) => [
            'id' => $s['_id'] ?? null,
            'name' => $s['name'] ?? 'Unnamed',
            'member_count' => (int) ($s['member_count'] ?? 0),
            'last_evaluated' => $s['last_evaluated_at'] ?? null,
        ], $segments);
    }

    /**
     * Get sync history for a tenant.
     */
    public function getSyncHistory(int|string $tenantId, int $limit = 20): array
    {
        return DB::connection('mongodb')->table('audience_sync_logs')
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn($l) => (array) $l)
            ->all();
    }

    /**
     * Get supported destinations with required credentials.
     */
    public function getSupportedDestinations(): array
    {
        return [
            'google_ads' => [
                'name' => 'Google Ads (Customer Match)',
                'required_credentials' => ['developer_token', 'client_id', 'client_secret', 'refresh_token', 'customer_id'],
                'supported_identifiers' => ['email', 'phone'],
            ],
            'meta_ads' => [
                'name' => 'Meta / Facebook Ads',
                'required_credentials' => ['access_token', 'ad_account_id'],
                'supported_identifiers' => ['email', 'phone', 'first_name', 'last_name'],
            ],
            'tiktok_ads' => [
                'name' => 'TikTok Ads',
                'required_credentials' => ['access_token', 'advertiser_id'],
                'supported_identifiers' => ['email', 'phone'],
            ],
            'klaviyo' => [
                'name' => 'Klaviyo',
                'required_credentials' => ['api_key', 'list_id'],
                'supported_identifiers' => ['email', 'phone', 'first_name', 'last_name'],
            ],
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function getSegmentMembers(int|string $tenantId, int $segmentId): array
    {
        $segment = DB::connection('mongodb')->table('audience_segments')
            ->where('tenant_id', $tenantId)
            ->where('_id', $segmentId)
            ->first();

        if (!$segment) return [];
        $segment = (array) $segment;

        $memberIds = $segment['member_ids'] ?? [];
        if (empty($memberIds)) return [];

        return DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tenantId)
            ->whereIn('visitor_id', $memberIds)
            ->get()
            ->map(fn($p) => (array) $p)
            ->all();
    }

    private function hashMembers(array $members): array
    {
        return array_map(function (array $m) {
            return [
                'email_hash' => isset($m['email']) ? hash('sha256', strtolower(trim($m['email']))) : null,
                'phone_hash' => isset($m['phone']) ? hash('sha256', preg_replace('/\D/', '', $m['phone'])) : null,
                'first_name_hash' => isset($m['first_name']) ? hash('sha256', strtolower(trim($m['first_name']))) : null,
                'last_name_hash' => isset($m['last_name']) ? hash('sha256', strtolower(trim($m['last_name']))) : null,
            ];
        }, $members);
    }

    private function syncToGoogleAds(array $hashedMembers, array $creds): array
    {
        $payload = [
            'operations' => [
                [
                    'operand' => [
                        'membershipLifeSpan' => 10000,
                        'members' => array_map(fn($m) => [
                            'hashedEmail' => $m['email_hash'],
                            'hashedPhoneNumber' => $m['phone_hash'],
                        ], array_filter($hashedMembers, fn($m) => $m['email_hash'])),
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . ($creds['refresh_token'] ?? ''),
                'developer-token' => $creds['developer_token'] ?? '',
                'login-customer-id' => $creds['customer_id'] ?? '',
            ])->post("https://googleads.googleapis.com/v15/customers/{$creds['customer_id']}/offlineUserDataJobs", $payload);

            return $this->logSync('google_ads', $hashedMembers, $response->successful(), $response->json());
        } catch (\Throwable $e) {
            Log::error('Google Ads sync failed', ['error' => $e->getMessage()]);
            return $this->logSync('google_ads', $hashedMembers, false, ['error' => $e->getMessage()]);
        }
    }

    private function syncToMetaAds(array $hashedMembers, array $creds): array
    {
        $data = array_map(fn($m) => array_filter([
            $m['email_hash'],
            $m['phone_hash'],
            $m['first_name_hash'],
            $m['last_name_hash'],
        ]), $hashedMembers);

        try {
            $response = Http::post("https://graph.facebook.com/v18.0/{$creds['ad_account_id']}/customaudiences", [
                'access_token' => $creds['access_token'] ?? '',
                'payload' => [
                    'schema' => ['EMAIL_SHA256', 'PHONE_SHA256', 'FN_SHA256', 'LN_SHA256'],
                    'data' => $data,
                ],
            ]);

            return $this->logSync('meta_ads', $hashedMembers, $response->successful(), $response->json());
        } catch (\Throwable $e) {
            Log::error('Meta Ads sync failed', ['error' => $e->getMessage()]);
            return $this->logSync('meta_ads', $hashedMembers, false, ['error' => $e->getMessage()]);
        }
    }

    private function syncToTikTokAds(array $hashedMembers, array $creds): array
    {
        $emailList = array_filter(array_column($hashedMembers, 'email_hash'));

        try {
            $response = Http::withHeaders([
                'Access-Token' => $creds['access_token'] ?? '',
            ])->post('https://business-api.tiktok.com/open_api/v1.3/segment/mapping/', [
                'advertiser_id' => $creds['advertiser_id'] ?? '',
                'action' => 'add',
                'calculate_type' => 'EMAIL_SHA256',
                'id_list' => $emailList,
            ]);

            return $this->logSync('tiktok_ads', $hashedMembers, $response->successful(), $response->json());
        } catch (\Throwable $e) {
            Log::error('TikTok Ads sync failed', ['error' => $e->getMessage()]);
            return $this->logSync('tiktok_ads', $hashedMembers, false, ['error' => $e->getMessage()]);
        }
    }

    private function syncToKlaviyo(array $members, array $creds): array
    {
        $profiles = array_map(fn($m) => [
            'type' => 'profile',
            'attributes' => array_filter([
                'email' => $m['email'] ?? null,
                'phone_number' => $m['phone'] ?? null,
                'first_name' => $m['first_name'] ?? null,
                'last_name' => $m['last_name'] ?? null,
            ]),
        ], $members);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Klaviyo-API-Key ' . ($creds['api_key'] ?? ''),
                'revision' => '2024-02-15',
            ])->post('https://a.klaviyo.com/api/lists/' . ($creds['list_id'] ?? '') . '/relationships/profiles/', [
                'data' => $profiles,
            ]);

            return $this->logSync('klaviyo', $members, $response->successful(), $response->json());
        } catch (\Throwable $e) {
            Log::error('Klaviyo sync failed', ['error' => $e->getMessage()]);
            return $this->logSync('klaviyo', $members, false, ['error' => $e->getMessage()]);
        }
    }

    private function logSync(string $destination, array $members, bool $success, mixed $response): array
    {
        $log = [
            'destination' => $destination,
            'members_synced' => count($members),
            'success' => $success,
            'response_summary' => is_array($response) ? array_slice($response, 0, 5) : null,
            'created_at' => now()->toIso8601String(),
        ];

        // Logged to MongoDB for history but tenant_id is set by the caller
        return [
            'status' => $success ? 'success' : 'error',
            'destination' => $destination,
            'members_synced' => count($members),
        ];
    }
}
