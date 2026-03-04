<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Events\IntegrationEvent;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Channels\ChannelManager;
use Modules\Marketing\Models\Campaign;
use Modules\Marketing\Models\Channel;
use Modules\Marketing\Models\Contact;
use Modules\Marketing\Models\Message;
use Modules\Marketing\Models\Template;

/**
 * Orchestrates campaign lifecycle: creation, audience resolution,
 * message generation, batch sending, and stats aggregation.
 */
final class CampaignService
{
    public function __construct(
        private readonly ChannelManager $channelManager,
        private readonly ContactService $contactService,
        private readonly TemplateService $templateService,
        private readonly VariableResolverService $variableResolver,
    ) {}

    // ─── CRUD Methods ─────────────────────────────────────────────────

    /**
     * List campaigns for a tenant with optional filters.
     */
    public function list(int $tenantId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Campaign::where('tenant_id', $tenantId)
            ->when($filters['status'] ?? null, fn($q, $s) => $q->where('status', $s))
            ->when($filters['channel'] ?? null, fn($q, $ch) => $q->where('channel', $ch))
            ->when($filters['type'] ?? null, fn($q, $t) => $q->where('type', $t))
            ->orderByDesc('updated_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Find a single campaign by ID scoped to tenant.
     */
    public function find(int $tenantId, int $id): ?Campaign
    {
        return Campaign::where('tenant_id', $tenantId)->find($id);
    }

    /**
     * Update a campaign.
     */
    public function update(int $tenantId, int $id, array $data): Campaign
    {
        $campaign = Campaign::where('tenant_id', $tenantId)->findOrFail($id);
        $campaign->update($data);
        return $campaign->fresh();
    }

    /**
     * Delete a campaign.
     */
    public function delete(int $tenantId, int $id): void
    {
        Campaign::where('tenant_id', $tenantId)->findOrFail($id)->delete();
    }

    /**
     * Duplicate a campaign.
     */
    public function duplicate(int $tenantId, int $id): Campaign
    {
        $original = Campaign::where('tenant_id', $tenantId)->findOrFail($id);
        $copy = $original->replicate();
        $copy->name = "{$original->name} (Copy)";
        $copy->status = 'draft';
        $copy->total_sent = 0;
        $copy->total_delivered = 0;
        $copy->total_opened = 0;
        $copy->total_clicked = 0;
        $copy->total_converted = 0;
        $copy->total_bounced = 0;
        $copy->total_unsubscribed = 0;
        $copy->total_revenue = 0;
        $copy->sent_at = null;
        $copy->completed_at = null;
        $copy->save();
        return $copy;
    }

    // ─── Campaign Creation & Sending ──────────────────────────────────

    /**
     * Create a new campaign (draft state).
     */
    public function create(int $tenantId, array $data): Campaign
    {
        return Campaign::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'one_time',
            'channel' => $data['channel'],
            'status' => 'draft',
            'template_id' => $data['template_id'] ?? null,
            'audience' => $data['audience'] ?? ['type' => 'all'],
            'schedule' => $data['schedule'] ?? null,
            'ab_variants' => $data['ab_variants'] ?? null,
        ]);
    }

    /**
     * Send a campaign to its audience. Generates Message records and
     * dispatches sending through the appropriate channel provider.
     *
     * @return array{total: int, sent: int, failed: int}
     */
    public function send(Campaign $campaign): array
    {
        $campaign->update(['status' => 'sending', 'sent_at' => now()]);

        $template = $campaign->template;
        if (!$template) {
            $campaign->update(['status' => 'failed']);
            throw new \RuntimeException("Campaign #{$campaign->id} has no template assigned.");
        }

        $channel = Channel::where('tenant_id', $campaign->tenant_id)
            ->where('type', $campaign->channel)
            ->where('is_active', true)
            ->first();

        if (!$channel) {
            $campaign->update(['status' => 'failed']);
            throw new \RuntimeException("No active {$campaign->channel} channel found for tenant #{$campaign->tenant_id}.");
        }

        $provider = $this->channelManager->resolve($channel);
        $contacts = $this->contactService->resolveAudience($campaign->tenant_id, $campaign->audience ?? []);

        $stats = ['total' => $contacts->count(), 'sent' => 0, 'failed' => 0];

        $shopContext = $this->getShopContext($campaign->tenant_id);

        foreach ($contacts as $contact) {
            try {
                $result = $this->sendToContact($campaign, $template, $channel, $provider, $contact, $shopContext);
                if ($result) {
                    $stats['sent']++;
                } else {
                    $stats['failed']++;
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::error("[CampaignService] Failed to send to {$contact->email}: {$e->getMessage()}");
            }
        }

        // Update campaign aggregates
        $campaign->update([
            'status' => 'sent',
            'total_sent' => $stats['sent'],
            'completed_at' => now(),
        ]);

        // Fire integration event for analytics tracking
        IntegrationEvent::dispatch('Marketing', 'campaign_sent', [
            'tenant_id' => $campaign->tenant_id,
            'campaign_id' => $campaign->id,
            'channel' => $campaign->channel,
            'total_sent' => $stats['sent'],
            'total_failed' => $stats['failed'],
        ]);

        Log::info("[CampaignService] Campaign #{$campaign->id} complete", $stats);

        return $stats;
    }

    /**
     * Send A/B test campaign. Splits audience, sends variants, tracks performance.
     */
    public function sendAbTest(Campaign $campaign): array
    {
        $variants = $campaign->ab_variants ?? [];
        if (count($variants) < 2) {
            throw new \RuntimeException('A/B test requires at least 2 variants.');
        }

        $contacts = $this->contactService->resolveAudience($campaign->tenant_id, $campaign->audience ?? []);
        $chunks = $contacts->shuffle()->split(count($variants));

        $results = [];
        foreach ($variants as $i => $variant) {
            $variantContacts = $chunks[$i] ?? collect();
            $template = Template::find($variant['template_id']);
            if (!$template) continue;

            $results["variant_{$i}"] = [
                'template_id' => $variant['template_id'],
                'contacts' => $variantContacts->count(),
                'sent' => 0,
                'failed' => 0,
            ];

            $channel = Channel::where('tenant_id', $campaign->tenant_id)
                ->where('type', $campaign->channel)
                ->where('is_active', true)
                ->first();

            if (!$channel) continue;

            $provider = $this->channelManager->resolve($channel);
            $shopContext = $this->getShopContext($campaign->tenant_id);

            foreach ($variantContacts as $contact) {
                try {
                    $sent = $this->sendToContact($campaign, $template, $channel, $provider, $contact, $shopContext);
                    $results["variant_{$i}"][$sent ? 'sent' : 'failed']++;
                } catch (\Throwable) {
                    $results["variant_{$i}"]['failed']++;
                }
            }
        }

        $campaign->update(['status' => 'sent', 'completed_at' => now()]);
        return $results;
    }

    /**
     * Refresh campaign statistics from individual message records.
     *
     * Accepts either (Campaign) or (tenantId, campaignId) signature.
     */
    public function refreshStats(Campaign|int $campaignOrTenantId, ?int $campaignId = null): void
    {
        if ($campaignOrTenantId instanceof Campaign) {
            $campaign = $campaignOrTenantId;
        } else {
            $campaign = Campaign::where('tenant_id', $campaignOrTenantId)->findOrFail($campaignId);
        }

        $messages = $campaign->messages();

        $campaign->update([
            'total_sent' => $messages->where('status', '!=', 'queued')->count(),
            'total_delivered' => $messages->whereNotNull('delivered_at')->count(),
            'total_opened' => $messages->whereNotNull('opened_at')->count(),
            'total_clicked' => $messages->whereNotNull('clicked_at')->count(),
            'total_bounced' => $messages->where('status', 'bounced')->count(),
            'total_unsubscribed' => $messages->where('status', 'unsubscribed')->count(),
        ]);
    }

    /**
     * Process a delivery webhook (open, click, bounce, etc.).
     */
    public function processWebhook(string $externalId, string $event, array $metadata = []): void
    {
        $message = Message::where('external_id', $externalId)->first();
        if (!$message) {
            Log::warning("[CampaignService] Webhook for unknown external_id: {$externalId}");
            return;
        }

        match ($event) {
            'delivered' => $message->update(['status' => 'delivered', 'delivered_at' => now()]),
            'opened', 'open' => $message->update(['status' => 'opened', 'opened_at' => $message->opened_at ?? now()]),
            'clicked', 'click' => $message->update(['status' => 'clicked', 'clicked_at' => $message->clicked_at ?? now()]),
            'bounced', 'bounce' => $message->update(['status' => 'bounced', 'error_message' => $metadata['reason'] ?? 'bounced']),
            'failed', 'dropped' => $message->update(['status' => 'failed', 'error_message' => $metadata['reason'] ?? 'failed']),
            'unsubscribed' => $this->handleUnsubscribe($message),
            default => Log::debug("[CampaignService] Ignoring webhook event: {$event}"),
        };

        // Broadcast for real-time dashboard updates
        IntegrationEvent::dispatch('Marketing', 'message_status_updated', [
            'message_id' => $message->id,
            'campaign_id' => $message->campaign_id,
            'status' => $event,
        ]);
    }

    private function sendToContact(
        Campaign $campaign,
        Template $template,
        Channel $channel,
        mixed $provider,
        Contact $contact,
        array $shopContext,
    ): bool {
        $contactData = $contact->toArray();
        $context = array_merge($shopContext, [
            'campaign' => ['name' => $campaign->name, 'id' => $campaign->id],
        ]);

        // Resolve variables and render template
        $variables = $this->variableResolver->resolve($contactData, $context);
        $rendered = $template->render($variables);

        // Determine recipient address based on channel
        $to = match ($campaign->channel) {
            'email' => $contact->email,
            'sms', 'whatsapp', 'rcs' => $contact->phone,
            'push' => $contact->push_token ?? '',
            default => $contact->email,
        };

        if (empty($to)) {
            Log::warning("[CampaignService] No recipient address for contact #{$contact->id} on {$campaign->channel}");
            return false;
        }

        // Create message record
        $message = Message::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'template_id' => $template->id,
            'channel' => $campaign->channel,
            'status' => 'sending',
            'variables_resolved' => $variables,
        ]);

        // Send via channel provider
        $result = $provider->send($channel, $message, $rendered, $to);

        // Update message status
        $message->update([
            'status' => $result['success'] ? 'sent' : 'failed',
            'external_id' => $result['external_id'],
            'error_message' => $result['error'],
            'sent_at' => $result['success'] ? now() : null,
        ]);

        return $result['success'];
    }

    private function handleUnsubscribe(Message $message): void
    {
        $message->update(['status' => 'unsubscribed']);
        if ($message->contact) {
            app(ContactService::class)->unsubscribe($message->contact);
        }
    }

    private function getShopContext(int $tenantId): array
    {
        try {
            $tenant = \App\Models\Tenant::find($tenantId);
            return [
                'tenant_id' => (string) $tenantId,
                'shop' => [
                    'name' => $tenant?->name ?? '',
                    'domain' => $tenant?->domain ?? '',
                ],
            ];
        } catch (\Throwable) {
            return ['tenant_id' => (string) $tenantId, 'shop' => []];
        }
    }
}
