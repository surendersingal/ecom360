<?php

declare(strict_types=1);

namespace Modules\Analytics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Models\TenantWebhook;

/**
 * Queued job that POSTs a tracking-event payload to a tenant's
 * external webhook endpoint.
 *
 * Security: The payload is signed with HMAC SHA-256 using the
 * TenantWebhook's secret_key so the recipient can verify authenticity.
 *
 * Performance: Uses a 5-second timeout and 2 retries so a slow
 * third-party server never blocks the main API throughput.
 */
final class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The queue connection this job runs on.
     */
    public string $connection = 'redis';

    /**
     * The queue this job is dispatched to.
     */
    public string $queue = 'webhooks';

    /**
     * Number of retry attempts.
     */
    public int $tries = 3;

    /**
     * Backoff strategy: 10s, 30s, 60s.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @param TenantWebhook        $webhook  The webhook configuration.
     * @param array<string, mixed>  $payload  The event data to send.
     */
    public function __construct(
        public readonly TenantWebhook $webhook,
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        $body = json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type'       => 'application/json',
            'X-Ecom360-Event'    => $this->payload['event_type'] ?? 'unknown',
            'X-Ecom360-Delivery' => $this->job?->uuid() ?? uniqid('wh_', true),
        ];

        // --- HMAC SHA-256 signature (if a secret_key is configured) ------
        if ($this->webhook->secret_key !== null && $this->webhook->secret_key !== '') {
            $signature = hash_hmac('sha256', $body, $this->webhook->secret_key);
            $headers['X-Ecom360-Signature'] = $signature;
        }

        $response = Http::timeout(5)
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($this->webhook->endpoint_url);

        if ($response->failed()) {
            Log::warning("[Webhook] Failed delivery to [{$this->webhook->endpoint_url}] — HTTP {$response->status()}", [
                'webhook_id' => $this->webhook->id,
                'status'     => $response->status(),
            ]);

            // Throw so Laravel retries up to $this->tries.
            $response->throw();
        }

        Log::debug("[Webhook] Delivered to [{$this->webhook->endpoint_url}] — HTTP {$response->status()}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("[Webhook] Permanently failed for webhook [{$this->webhook->id}]", [
            'endpoint_url' => $this->webhook->endpoint_url,
            'error'        => $exception?->getMessage(),
        ]);
    }
}
