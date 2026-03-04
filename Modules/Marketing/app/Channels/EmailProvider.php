<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Marketing\Contracts\ChannelProviderInterface;
use Modules\Marketing\Models\Channel;
use Modules\Marketing\Models\Message;

/**
 * Sends messages via email using Laravel's Mail facade.
 *
 * Supported providers (configured per-Channel):
 *   - smtp (default Laravel mailer)
 *   - sendgrid (via SMTP or API)
 *   - mailgun
 *   - ses (Amazon SES)
 *   - postmark
 *
 * Credentials shape:
 *   smtp: { host, port, username, password, encryption }
 *   sendgrid: { api_key }
 *   mailgun: { domain, secret, endpoint? }
 *   ses: { key, secret, region }
 *   postmark: { token }
 */
final class EmailProvider implements ChannelProviderInterface
{
    public function type(): string
    {
        return 'email';
    }

    public function send(Channel $channel, Message $message, array $rendered, string $to): array
    {
        try {
            $fromAddress = $channel->settings['from_address'] ?? config('mail.from.address');
            $fromName = $channel->settings['from_name'] ?? config('mail.from.name');

            $mailerConfig = $this->buildMailerConfig($channel);

            // Dynamically configure the mailer for this channel's credentials
            config(["mail.mailers.marketing_{$channel->id}" => $mailerConfig]);

            Mail::mailer("marketing_{$channel->id}")
                ->html($rendered['html'] ?? $rendered['text'] ?? '', function ($mail) use ($to, $rendered, $fromAddress, $fromName) {
                    $mail->to($to)
                        ->from($fromAddress, $fromName)
                        ->subject($rendered['subject'] ?? 'No Subject');

                    if (!empty($rendered['text'])) {
                        $mail->text('emails.raw-text', ['content' => $rendered['text']]);
                    }
                });

            Log::info("[EmailProvider] Sent to {$to}", ['message_id' => $message->id]);

            return [
                'success' => true,
                'external_id' => null, // Would come from webhook / return header
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::error("[EmailProvider] Failed to send to {$to}: {$e->getMessage()}", [
                'message_id' => $message->id,
                'exception' => $e::class,
            ]);

            return [
                'success' => false,
                'external_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function validateCredentials(Channel $channel): bool
    {
        $creds = $channel->credentials ?? [];
        $provider = $channel->provider ?? 'smtp';

        return match ($provider) {
            'smtp' => !empty($creds['host']) && !empty($creds['port']),
            'sendgrid' => !empty($creds['api_key']),
            'mailgun' => !empty($creds['domain']) && !empty($creds['secret']),
            'ses' => !empty($creds['key']) && !empty($creds['secret']) && !empty($creds['region']),
            'postmark' => !empty($creds['token']),
            default => false,
        };
    }

    private function buildMailerConfig(Channel $channel): array
    {
        $creds = $channel->credentials ?? [];
        $provider = $channel->provider ?? 'smtp';

        return match ($provider) {
            'sendgrid' => [
                'transport' => 'smtp',
                'host' => 'smtp.sendgrid.net',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'apikey',
                'password' => $creds['api_key'] ?? '',
            ],
            'mailgun' => [
                'transport' => 'mailgun',
                'domain' => $creds['domain'] ?? '',
                'secret' => $creds['secret'] ?? '',
                'endpoint' => $creds['endpoint'] ?? 'api.mailgun.net',
            ],
            'ses' => [
                'transport' => 'ses',
                'key' => $creds['key'] ?? '',
                'secret' => $creds['secret'] ?? '',
                'region' => $creds['region'] ?? 'us-east-1',
            ],
            'postmark' => [
                'transport' => 'postmark',
                'token' => $creds['token'] ?? '',
            ],
            default => [ // smtp
                'transport' => 'smtp',
                'host' => $creds['host'] ?? 'localhost',
                'port' => (int) ($creds['port'] ?? 587),
                'encryption' => $creds['encryption'] ?? 'tls',
                'username' => $creds['username'] ?? '',
                'password' => $creds['password'] ?? '',
            ],
        };
    }
}
