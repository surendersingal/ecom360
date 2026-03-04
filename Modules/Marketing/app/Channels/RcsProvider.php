<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Contracts\ChannelProviderInterface;
use Modules\Marketing\Models\Channel;
use Modules\Marketing\Models\Message;

/**
 * Sends Rich Communication Services (RCS) messages via Google's
 * RCS Business Messaging API or alternative providers.
 *
 * Supported providers:
 *   - google (Google RCS Business Messaging / Jibe)
 *   - sinch
 *   - infobip
 *
 * Credentials shape:
 *   google: { service_account_json, agent_id }
 *   sinch: { project_id, client_id, client_secret }
 *   infobip: { api_key, base_url, sender }
 */
final class RcsProvider implements ChannelProviderInterface
{
    public function type(): string
    {
        return 'rcs';
    }

    public function send(Channel $channel, Message $message, array $rendered, string $to): array
    {
        $provider = $channel->provider ?? 'google';

        return match ($provider) {
            'google' => $this->sendViaGoogle($channel, $message, $rendered, $to),
            'sinch' => $this->sendViaSinch($channel, $message, $rendered, $to),
            'infobip' => $this->sendViaInfobip($channel, $message, $rendered, $to),
            default => ['success' => false, 'external_id' => null, 'error' => "Unknown RCS provider: {$provider}"],
        };
    }

    public function validateCredentials(Channel $channel): bool
    {
        $creds = $channel->credentials ?? [];
        $provider = $channel->provider ?? 'google';

        return match ($provider) {
            'google' => !empty($creds['service_account_json']) && !empty($creds['agent_id']),
            'sinch' => !empty($creds['project_id']) && !empty($creds['client_id']) && !empty($creds['client_secret']),
            'infobip' => !empty($creds['api_key']) && !empty($creds['base_url']),
            default => false,
        };
    }

    private function sendViaGoogle(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $creds = $channel->credentials;
            $agentId = $creds['agent_id'];

            // Google RCS Business Messaging uses OAuth2 service account auth.
            // In production, use Google\Client or firebase/php-jwt for token generation.
            $accessToken = $this->getGoogleAccessToken($creds['service_account_json']);

            $to = preg_replace('/[^\d+]/', '', $to);
            $body = $rendered['text'] ?? strip_tags($rendered['html'] ?? '');

            // Build RCS content message with optional rich card
            $contentMessage = ['text' => $body];
            if (!empty($rendered['subject'])) {
                $contentMessage = [
                    'richCard' => [
                        'standaloneCard' => [
                            'cardContent' => [
                                'title' => $rendered['subject'],
                                'description' => $body,
                            ],
                        ],
                    ],
                ];
            }

            $response = Http::withToken($accessToken)
                ->post("https://rcsbusinessmessaging.googleapis.com/v1/phones/{$to}/agentMessages", [
                    'contentMessage' => $contentMessage,
                    'agentId' => $agentId,
                ]);

            if ($response->successful()) {
                $msgName = $response->json('name');
                Log::info("[RCS/Google] Sent to {$to}", ['name' => $msgName]);
                return ['success' => true, 'external_id' => $msgName, 'error' => null];
            }

            $error = $response->json('error.message', $response->body());
            return ['success' => false, 'external_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendViaSinch(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $creds = $channel->credentials;
            $projectId = $creds['project_id'];
            $clientId = $creds['client_id'];
            $clientSecret = $creds['client_secret'];

            // Sinch Conversation API
            $tokenResponse = Http::asForm()->post('https://auth.sinch.com/oauth2/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

            $accessToken = $tokenResponse->json('access_token');
            $body = $rendered['text'] ?? strip_tags($rendered['html'] ?? '');

            $response = Http::withToken($accessToken)
                ->post("https://us.conversation.api.sinch.com/v1/projects/{$projectId}/messages:send", [
                    'app_id' => $creds['app_id'] ?? $projectId,
                    'recipient' => ['contact_id' => $to],
                    'message' => [
                        'text_message' => ['text' => $body],
                    ],
                    'channel_priority_order' => ['RCS'],
                ]);

            if ($response->successful()) {
                $msgId = $response->json('message_id');
                return ['success' => true, 'external_id' => $msgId, 'error' => null];
            }

            return ['success' => false, 'external_id' => null, 'error' => $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendViaInfobip(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $creds = $channel->credentials;
            $apiKey = $creds['api_key'];
            $baseUrl = rtrim($creds['base_url'], '/');
            $sender = $creds['sender'] ?? 'ecom360';

            $body = $rendered['text'] ?? strip_tags($rendered['html'] ?? '');

            $response = Http::withHeaders([
                'Authorization' => "App {$apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$baseUrl}/ott/rcs/1/message", [
                'from' => $sender,
                'to' => preg_replace('/[^\d]/', '', $to),
                'content' => [
                    'type' => 'TEXT',
                    'text' => $body,
                ],
            ]);

            if ($response->successful()) {
                $msgId = $response->json('messageId');
                return ['success' => true, 'external_id' => $msgId, 'error' => null];
            }

            return ['success' => false, 'external_id' => null, 'error' => $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function getGoogleAccessToken(string $serviceAccountJson): string
    {
        $sa = json_decode($serviceAccountJson, true);

        // JWT claim set for Google OAuth2
        $now = time();
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = base64_encode(json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/rcsbusinessmessaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';
        openssl_sign("{$header}.{$claim}", $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = "{$header}.{$claim}." . base64_encode($signature);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        return $response->json('access_token', '');
    }
}
