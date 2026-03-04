<?php

declare(strict_types=1);

namespace Modules\Marketing\Contracts;

use Modules\Marketing\Models\Channel;
use Modules\Marketing\Models\Message;

/**
 * Every messaging channel (email, WhatsApp, RCS, push, SMS) implements
 * this contract so the CampaignService can send messages polymorphically.
 */
interface ChannelProviderInterface
{
    /**
     * Send a single message through this channel.
     *
     * @param  Channel  $channel   The configured channel instance with credentials.
     * @param  Message  $message   The message record to send.
     * @param  array    $rendered  Pre-rendered template output: [subject, html, text].
     * @param  string   $to        Recipient address (email, phone number, device token).
     * @return array{success: bool, external_id: ?string, error: ?string}
     */
    public function send(Channel $channel, Message $message, array $rendered, string $to): array;

    /**
     * Validate that the channel credentials are correctly configured.
     */
    public function validateCredentials(Channel $channel): bool;

    /**
     * Return the channel type identifier (email, whatsapp, rcs, push, sms).
     */
    public function type(): string;
}
