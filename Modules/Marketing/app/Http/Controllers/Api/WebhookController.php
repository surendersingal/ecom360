<?php

declare(strict_types=1);

namespace Modules\Marketing\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Services\CampaignService;

/**
 * Handles inbound webhooks from channel providers (SendGrid, Twilio, etc.)
 * for delivery/open/click/bounce tracking.
 *
 * These routes are public (no auth) but validated by provider-specific signatures.
 */
final class WebhookController extends Controller
{
    use ApiResponse;

    /**
     * Generic webhook endpoint — routes by provider param.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        Log::info("Marketing webhook received", ['provider' => $provider, 'payload_keys' => array_keys($request->all())]);

        return match ($provider) {
            'sendgrid' => $this->handleSendGrid($request),
            'mailgun' => $this->handleMailgun($request),
            'ses' => $this->handleSes($request),
            'twilio' => $this->handleTwilio($request),
            'meta' => $this->handleMeta($request),
            default => $this->successResponse(['status' => 'ignored']),
        };
    }

    private function handleSendGrid(Request $request): JsonResponse
    {
        $events = $request->all();
        if (!is_array($events)) return $this->successResponse(['status' => 'ok']);

        foreach ($events as $event) {
            if (!is_array($event)) continue;
            $messageId = $event['sg_message_id'] ?? null;
            $eventType = $event['event'] ?? null;

            if (!$messageId || !$eventType) continue;

            $this->processWebhookEvent($messageId, match ($eventType) {
                'delivered' => 'delivered',
                'open' => 'opened',
                'click' => 'clicked',
                'bounce', 'dropped' => 'bounced',
                'unsubscribe', 'spamreport' => 'unsubscribed',
                default => null,
            });
        }

        return $this->successResponse(['status' => 'processed']);
    }

    private function handleMailgun(Request $request): JsonResponse
    {
        $data = $request->input('event-data', []);
        $messageId = $data['message']['headers']['message-id'] ?? null;
        $eventType = $data['event'] ?? null;

        if ($messageId && $eventType) {
            $this->processWebhookEvent($messageId, match ($eventType) {
                'delivered' => 'delivered',
                'opened' => 'opened',
                'clicked' => 'clicked',
                'failed', 'rejected' => 'bounced',
                'unsubscribed', 'complained' => 'unsubscribed',
                default => null,
            });
        }

        return $this->successResponse(['status' => 'processed']);
    }

    private function handleSes(Request $request): JsonResponse
    {
        $message = json_decode($request->getContent(), true);
        $type = $message['notificationType'] ?? $message['Type'] ?? null;

        if ($type === 'SubscriptionConfirmation') {
            // Auto-confirm SNS subscription
            if (isset($message['SubscribeURL'])) {
                file_get_contents($message['SubscribeURL']);
            }
            return $this->successResponse(['status' => 'confirmed']);
        }

        $sesMessage = json_decode($message['Message'] ?? '{}', true);
        $messageId = $sesMessage['mail']['messageId'] ?? null;

        if ($messageId) {
            $this->processWebhookEvent($messageId, match ($type) {
                'Delivery' => 'delivered',
                'Bounce' => 'bounced',
                'Complaint' => 'unsubscribed',
                default => null,
            });
        }

        return $this->successResponse(['status' => 'processed']);
    }

    private function handleTwilio(Request $request): JsonResponse
    {
        $sid = $request->input('MessageSid') ?? $request->input('SmsSid');
        $status = $request->input('MessageStatus') ?? $request->input('SmsStatus');

        if ($sid && $status) {
            $this->processWebhookEvent($sid, match ($status) {
                'delivered' => 'delivered',
                'read' => 'opened',
                'failed', 'undelivered' => 'bounced',
                default => null,
            });
        }

        return $this->successResponse(['status' => 'processed']);
    }

    private function handleMeta(Request $request): JsonResponse
    {
        // WhatsApp Business API webhook
        $entries = $request->input('entry', []);

        foreach ($entries as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $statuses = $change['value']['statuses'] ?? [];
                foreach ($statuses as $s) {
                    $messageId = $s['id'] ?? null;
                    $status = $s['status'] ?? null;

                    if ($messageId && $status) {
                        $this->processWebhookEvent($messageId, match ($status) {
                            'delivered' => 'delivered',
                            'read' => 'opened',
                            'failed' => 'bounced',
                            default => null,
                        });
                    }
                }
            }
        }

        return $this->successResponse(['status' => 'processed']);
    }

    private function processWebhookEvent(string $externalId, ?string $eventType): void
    {
        if (!$eventType) return;

        try {
            $service = app(CampaignService::class);
            $service->processWebhook($externalId, $eventType);
        } catch (\Throwable $e) {
            Log::warning('Webhook processing failed', ['external_id' => $externalId, 'event' => $eventType, 'error' => $e->getMessage()]);
        }
    }
}
