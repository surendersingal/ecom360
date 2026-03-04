<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Contracts\ChannelProviderInterface;
use Modules\Marketing\Models\Channel;
use Modules\Marketing\Models\Message;

/**
 * Sends SMS messages via various providers.
 *
 * Supported providers:
 *   - twilio
 *   - vonage (Nexmo)
 *   - sns (Amazon SNS)
 *   - msg91
 *
 * Credentials shape:
 *   twilio: { account_sid, auth_token, from_number }
 *   vonage: { api_key, api_secret, from }
 *   sns: { key, secret, region }
 *   msg91: { auth_key, sender_id, route }
 */
final class SmsProvider implements ChannelProviderInterface
{
    public function type(): string
    {
        return 'sms';
    }

    public function send(Channel $channel, Message $message, array $rendered, string $to): array
    {
        $provider = $channel->provider ?? 'twilio';

        return match ($provider) {
            'twilio' => $this->sendViaTwilio($channel, $message, $rendered, $to),
            'vonage' => $this->sendViaVonage($channel, $message, $rendered, $to),
            'msg91' => $this->sendViaMsg91($channel, $message, $rendered, $to),
            default => ['success' => false, 'external_id' => null, 'error' => "Unknown SMS provider: {$provider}"],
        };
    }

    public function validateCredentials(Channel $channel): bool
    {
        $creds = $channel->credentials ?? [];
        $provider = $channel->provider ?? 'twilio';

        return match ($provider) {
            'twilio' => !empty($creds['account_sid']) && !empty($creds['auth_token']) && !empty($creds['from_number']),
            'vonage' => !empty($creds['api_key']) && !empty($creds['api_secret']),
            'msg91' => !empty($creds['auth_key']) && !empty($creds['sender_id']),
            default => false,
        };
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
                    'To' => $to,
                    'From' => $from,
                    'Body' => $body,
                ]);

            if ($response->successful()) {
                $sid = $response->json('sid');
                Log::info("[SMS/Twilio] Sent to {$to}", ['sid' => $sid]);
                return ['success' => true, 'external_id' => $sid, 'error' => null];
            }

            return ['success' => false, 'external_id' => null, 'error' => $response->json('message', $response->body())];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendViaVonage(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $creds = $channel->credentials;
            $body = $rendered['text'] ?? strip_tags($rendered['html'] ?? '');

            $response = Http::post('https://rest.nexmo.com/sms/json', [
                'api_key' => $creds['api_key'],
                'api_secret' => $creds['api_secret'],
                'to' => preg_replace('/[^\d]/', '', $to),
                'from' => $creds['from'] ?? 'Ecom360',
                'text' => $body,
            ]);

            if ($response->successful()) {
                $msgData = $response->json('messages.0');
                if (($msgData['status'] ?? '') === '0') {
                    return ['success' => true, 'external_id' => $msgData['message-id'] ?? null, 'error' => null];
                }
                return ['success' => false, 'external_id' => null, 'error' => $msgData['error-text'] ?? 'Unknown error'];
            }

            return ['success' => false, 'external_id' => null, 'error' => $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendViaMsg91(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $creds = $channel->credentials;
            $body = $rendered['text'] ?? strip_tags($rendered['html'] ?? '');

            $response = Http::withHeaders([
                'authkey' => $creds['auth_key'],
                'Content-Type' => 'application/json',
            ])->post('https://api.msg91.com/api/v5/flow/', [
                'sender' => $creds['sender_id'],
                'route' => $creds['route'] ?? '4',
                'mobiles' => preg_replace('/[^\d]/', '', $to),
                'body' => $body,
            ]);

            if ($response->successful()) {
                $reqId = $response->json('request_id');
                return ['success' => true, 'external_id' => $reqId, 'error' => null];
            }

            return ['success' => false, 'external_id' => null, 'error' => $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }
}
