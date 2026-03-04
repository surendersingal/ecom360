<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Contracts\ChannelProviderInterface;
use Modules\Marketing\Models\Channel;
use Modules\Marketing\Models\Message;

/**
 * Sends messages via WhatsApp Business API.
 *
 * Supported providers:
 *   - meta (official Meta Cloud API)
 *   - twilio (Twilio WhatsApp)
 *   - gupshup
 *
 * Credentials shape:
 *   meta: { access_token, phone_number_id, business_account_id }
 *   twilio: { account_sid, auth_token, from_number }
 *   gupshup: { api_key, app_name, source_number }
 */
final class WhatsAppProvider implements ChannelProviderInterface
{
    public function type(): string
    {
        return 'whatsapp';
    }

    public function send(Channel $channel, Message $message, array $rendered, string $to): array
    {
        $provider = $channel->provider ?? 'meta';

        return match ($provider) {
            'meta' => $this->sendViaMeta($channel, $message, $rendered, $to),
            'twilio' => $this->sendViaTwilio($channel, $message, $rendered, $to),
            'gupshup' => $this->sendViaGupshup($channel, $message, $rendered, $to),
            default => ['success' => false, 'external_id' => null, 'error' => "Unknown provider: {$provider}"],
        };
    }

    public function validateCredentials(Channel $channel): bool
    {
        $creds = $channel->credentials ?? [];
        $provider = $channel->provider ?? 'meta';

        return match ($provider) {
            'meta' => !empty($creds['access_token']) && !empty($creds['phone_number_id']),
            'twilio' => !empty($creds['account_sid']) && !empty($creds['auth_token']) && !empty($creds['from_number']),
            'gupshup' => !empty($creds['api_key']) && !empty($creds['app_name']),
            default => false,
        };
    }

    private function sendViaMeta(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $creds = $channel->credentials;
            $phoneNumberId = $creds['phone_number_id'];
            $accessToken = $creds['access_token'];

            // Clean phone number (remove +, spaces, dashes)
            $to = preg_replace('/[^\d]/', '', $to);

            $templateName = $channel->settings['template_name'] ?? null;

            // If a WhatsApp-approved template is configured, use template message
            if ($templateName) {
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => ['code' => $channel->settings['language'] ?? 'en'],
                        'components' => $this->buildTemplateComponents($rendered),
                    ],
                ];
            } else {
                // Freeform text message (only within 24h customer-initiated window)
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $rendered['text'] ?? strip_tags($rendered['html'] ?? '')],
                ];
            }

            $response = Http::withToken($accessToken)
                ->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages", $payload);

            if ($response->successful()) {
                $messageId = $response->json('messages.0.id');
                Log::info("[WhatsApp/Meta] Sent to {$to}", ['wa_id' => $messageId, 'message_id' => $message->id]);
                return ['success' => true, 'external_id' => $messageId, 'error' => null];
            }

            $error = $response->json('error.message', $response->body());
            Log::error("[WhatsApp/Meta] Failed: {$error}", ['to' => $to]);
            return ['success' => false, 'external_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendViaTwilio(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $creds = $channel->credentials;
            $accountSid = $creds['account_sid'];
            $authToken = $creds['auth_token'];
            $from = $creds['from_number'];

            $body = $rendered['text'] ?? strip_tags($rendered['html'] ?? '');

            $response = Http::withBasicAuth($accountSid, $authToken)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                    'To' => "whatsapp:{$to}",
                    'From' => "whatsapp:{$from}",
                    'Body' => $body,
                ]);

            if ($response->successful()) {
                $sid = $response->json('sid');
                Log::info("[WhatsApp/Twilio] Sent to {$to}", ['sid' => $sid]);
                return ['success' => true, 'external_id' => $sid, 'error' => null];
            }

            $error = $response->json('message', $response->body());
            return ['success' => false, 'external_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendViaGupshup(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $creds = $channel->credentials;
            $apiKey = $creds['api_key'];
            $source = $creds['source_number'];
            $appName = $creds['app_name'];

            $body = $rendered['text'] ?? strip_tags($rendered['html'] ?? '');

            $response = Http::withHeaders(['apikey' => $apiKey])
                ->asForm()
                ->post('https://api.gupshup.io/wa/api/v1/msg', [
                    'channel' => 'whatsapp',
                    'source' => $source,
                    'destination' => preg_replace('/[^\d]/', '', $to),
                    'message' => json_encode(['type' => 'text', 'text' => $body]),
                    'src.name' => $appName,
                ]);

            if ($response->successful() && $response->json('status') === 'submitted') {
                $msgId = $response->json('messageId');
                Log::info("[WhatsApp/Gupshup] Sent to {$to}", ['msg_id' => $msgId]);
                return ['success' => true, 'external_id' => $msgId, 'error' => null];
            }

            return ['success' => false, 'external_id' => null, 'error' => $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function buildTemplateComponents(array $rendered): array
    {
        $components = [];
        if (!empty($rendered['subject'])) {
            $components[] = [
                'type' => 'header',
                'parameters' => [['type' => 'text', 'text' => $rendered['subject']]],
            ];
        }
        if (!empty($rendered['text'])) {
            $components[] = [
                'type' => 'body',
                'parameters' => [['type' => 'text', 'text' => $rendered['text']]],
            ];
        }
        return $components;
    }
}
