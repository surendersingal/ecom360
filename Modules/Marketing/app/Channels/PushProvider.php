<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Contracts\ChannelProviderInterface;
use Modules\Marketing\Models\Channel;
use Modules\Marketing\Models\Message;

/**
 * Sends push notifications via Firebase Cloud Messaging (FCM) or
 * alternative push providers.
 *
 * Supported providers:
 *   - fcm (Firebase Cloud Messaging v1 API)
 *   - onesignal
 *   - expo
 *
 * Credentials shape:
 *   fcm: { service_account_json, project_id }
 *   onesignal: { app_id, rest_api_key }
 *   expo: { access_token? } (optional for Expo push)
 */
final class PushProvider implements ChannelProviderInterface
{
    public function type(): string
    {
        return 'push';
    }

    public function send(Channel $channel, Message $message, array $rendered, string $to): array
    {
        $provider = $channel->provider ?? 'fcm';

        return match ($provider) {
            'fcm' => $this->sendViaFcm($channel, $message, $rendered, $to),
            'onesignal' => $this->sendViaOneSignal($channel, $message, $rendered, $to),
            'expo' => $this->sendViaExpo($channel, $message, $rendered, $to),
            default => ['success' => false, 'external_id' => null, 'error' => "Unknown push provider: {$provider}"],
        };
    }

    public function validateCredentials(Channel $channel): bool
    {
        $creds = $channel->credentials ?? [];
        $provider = $channel->provider ?? 'fcm';

        return match ($provider) {
            'fcm' => !empty($creds['service_account_json']) && !empty($creds['project_id']),
            'onesignal' => !empty($creds['app_id']) && !empty($creds['rest_api_key']),
            'expo' => true, // Expo push can work without auth for basic usage
            default => false,
        };
    }

    private function sendViaFcm(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $creds = $channel->credentials;
            $projectId = $creds['project_id'];
            $accessToken = $this->getFcmAccessToken($creds['service_account_json']);

            $payload = [
                'message' => [
                    'token' => $to, // FCM device token
                    'notification' => [
                        'title' => $rendered['subject'] ?? 'Notification',
                        'body' => $rendered['text'] ?? strip_tags($rendered['html'] ?? ''),
                    ],
                    'data' => array_filter([
                        'message_id' => (string) $message->id,
                        'campaign_id' => (string) ($message->campaign_id ?? ''),
                        'click_action' => $channel->settings['click_action'] ?? null,
                        'deep_link' => $channel->settings['deep_link'] ?? null,
                    ]),
                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'channel_id' => $channel->settings['android_channel'] ?? 'marketing',
                            'icon' => $channel->settings['icon'] ?? null,
                            'color' => $channel->settings['color'] ?? null,
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'badge' => 1,
                                'sound' => 'default',
                            ],
                        ],
                    ],
                    'webpush' => [
                        'headers' => ['Urgency' => 'high'],
                        'notification' => [
                            'icon' => $channel->settings['web_icon'] ?? null,
                            'badge' => $channel->settings['web_badge'] ?? null,
                        ],
                    ],
                ],
            ];

            $response = Http::withToken($accessToken)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", $payload);

            if ($response->successful()) {
                $name = $response->json('name');
                Log::info("[Push/FCM] Sent to device", ['name' => $name, 'message_id' => $message->id]);
                return ['success' => true, 'external_id' => $name, 'error' => null];
            }

            $error = $response->json('error.message', $response->body());
            return ['success' => false, 'external_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendViaOneSignal(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $creds = $channel->credentials;
            $appId = $creds['app_id'];
            $apiKey = $creds['rest_api_key'];

            $response = Http::withHeaders([
                'Authorization' => "Basic {$apiKey}",
            ])->post('https://onesignal.com/api/v1/notifications', [
                'app_id' => $appId,
                'include_player_ids' => [$to],
                'headings' => ['en' => $rendered['subject'] ?? 'Notification'],
                'contents' => ['en' => $rendered['text'] ?? strip_tags($rendered['html'] ?? '')],
                'data' => [
                    'message_id' => $message->id,
                    'campaign_id' => $message->campaign_id,
                ],
            ]);

            if ($response->successful()) {
                $id = $response->json('id');
                return ['success' => true, 'external_id' => $id, 'error' => null];
            }

            return ['success' => false, 'external_id' => null, 'error' => $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendViaExpo(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $headers = ['Content-Type' => 'application/json'];
            if (!empty($channel->credentials['access_token'])) {
                $headers['Authorization'] = 'Bearer ' . $channel->credentials['access_token'];
            }

            $response = Http::withHeaders($headers)
                ->post('https://exp.host/--/api/v2/push/send', [
                    'to' => $to, // Expo push token
                    'title' => $rendered['subject'] ?? 'Notification',
                    'body' => $rendered['text'] ?? strip_tags($rendered['html'] ?? ''),
                    'data' => [
                        'message_id' => $message->id,
                        'campaign_id' => $message->campaign_id,
                    ],
                ]);

            if ($response->successful() && $response->json('data.status') === 'ok') {
                $id = $response->json('data.id');
                return ['success' => true, 'external_id' => $id, 'error' => null];
            }

            return ['success' => false, 'external_id' => null, 'error' => $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function getFcmAccessToken(string $serviceAccountJson): string
    {
        $sa = json_decode($serviceAccountJson, true);
        $now = time();

        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $claim = rtrim(strtr(base64_encode(json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ])), '+/', '-_'), '=');

        $signature = '';
        openssl_sign("{$header}.{$claim}", $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = "{$header}.{$claim}." . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        return $response->json('access_token', '');
    }
}
