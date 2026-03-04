<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use App\Events\IntegrationEvent;
use Illuminate\Support\Facades\Log;
use Modules\BusinessIntelligence\Models\Alert;
use Modules\BusinessIntelligence\Models\AlertHistory;
use Modules\BusinessIntelligence\Models\Kpi;

/**
 * Evaluates alert conditions against KPI values and dispatches
 * notifications when thresholds are breached.
 */
final class AlertService
{
    public function __construct(
        private readonly KpiService $kpiService,
    ) {}

    // ─── CRUD Methods ─────────────────────────────────────────────────

    /**
     * List alerts for a tenant with optional filters.
     */
    public function list(int $tenantId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Alert::where('tenant_id', $tenantId)
            ->when($filters['kpi_id'] ?? null, fn($q, $kpiId) => $q->where('kpi_id', $kpiId))
            ->when(isset($filters['is_active']), fn($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->with('kpi')
            ->orderByDesc('updated_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Create a new alert.
     */
    public function create(int $tenantId, array $data): Alert
    {
        return Alert::create(array_merge($data, [
            'tenant_id' => $tenantId,
            'is_active' => $data['is_active'] ?? true,
            'cooldown_minutes' => $data['cooldown_minutes'] ?? 60,
        ]));
    }

    /**
     * Find a single alert by ID scoped to tenant.
     */
    public function find(int $tenantId, int $id): ?Alert
    {
        return Alert::where('tenant_id', $tenantId)->with('kpi')->find($id);
    }

    /**
     * Update an alert.
     */
    public function update(int $tenantId, int $id, array $data): Alert
    {
        $alert = Alert::where('tenant_id', $tenantId)->findOrFail($id);
        $alert->update($data);
        return $alert->fresh();
    }

    /**
     * Delete an alert.
     */
    public function delete(int $tenantId, int $id): void
    {
        Alert::where('tenant_id', $tenantId)->findOrFail($id)->delete();
    }

    /**
     * Get alert trigger history.
     */
    public function getHistory(int $tenantId, int $alertId): \Illuminate\Database\Eloquent\Collection
    {
        $alert = Alert::where('tenant_id', $tenantId)->findOrFail($alertId);
        return $alert->history()->orderByDesc('created_at')->get();
    }

    /**
     * Acknowledge an alert history entry.
     */
    public function acknowledge(int $tenantId, int $alertHistoryId): void
    {
        $history = AlertHistory::findOrFail($alertHistoryId);

        // Verify the alert belongs to this tenant
        $alert = Alert::where('tenant_id', $tenantId)->findOrFail($history->alert_id);

        $history->acknowledge();
    }

    // ─── Evaluation ───────────────────────────────────────────────────

    /**
     * Evaluate all active alerts for a tenant.
     *
     * @return array{evaluated: int, triggered: int}
     */
    public function evaluateAll(int $tenantId): array
    {
        $alerts = Alert::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('kpi')
            ->get();

        $stats = ['evaluated' => 0, 'triggered' => 0];

        foreach ($alerts as $alert) {
            $stats['evaluated']++;
            if ($this->evaluate($alert)) {
                $stats['triggered']++;
            }
        }

        return $stats;
    }

    /**
     * Evaluate a single alert against its KPI.
     */
    public function evaluate(Alert $alert): bool
    {
        $kpi = $alert->kpi;
        if (!$kpi) {
            Log::warning("[AlertService] Alert #{$alert->id} has no KPI.");
            return false;
        }

        // Calculate the value to check
        $value = match ($alert->condition) {
            'above', 'below' => (float) $kpi->current_value,
            'change_percent' => (float) ($kpi->change_percent ?? 0),
            'anomaly' => $this->calculateAnomalyScore($kpi),
            default => 0.0,
        };

        if (!$alert->shouldTrigger($value)) {
            return false;
        }

        // Create history record
        $history = AlertHistory::create([
            'alert_id' => $alert->id,
            'triggered_value' => $value,
            'threshold_value' => $alert->threshold,
            'condition' => $alert->condition,
            'message' => $this->buildAlertMessage($alert, $kpi, $value),
            'notified_channels' => $alert->channels,
        ]);

        $alert->update(['last_triggered_at' => now()]);

        // Dispatch notifications
        $this->notify($alert, $history);

        // Fire integration event
        IntegrationEvent::dispatch('BusinessIntelligence', 'alert_triggered', [
            'tenant_id' => $alert->tenant_id,
            'alert_id' => $alert->id,
            'kpi' => $kpi->metric,
            'value' => $value,
            'threshold' => $alert->threshold,
            'condition' => $alert->condition,
        ]);

        Log::info("[AlertService] Alert #{$alert->id} triggered: {$kpi->metric} = {$value}");
        return true;
    }

    /**
     * Calculate a basic anomaly score using z-score method.
     * Historical values stored in alert history provide the baseline.
     */
    private function calculateAnomalyScore(Kpi $kpi): float
    {
        $history = AlertHistory::whereHas('alert', fn($q) => $q->where('kpi_id', $kpi->id))
            ->orderByDesc('created_at')
            ->limit(30)
            ->pluck('triggered_value')
            ->toArray();

        if (count($history) < 5) {
            return 0.0; // Not enough data for anomaly detection
        }

        $mean = array_sum($history) / count($history);
        $variance = array_sum(array_map(fn($v) => pow((float) $v - $mean, 2), $history)) / count($history);
        $stddev = sqrt($variance);

        if ($stddev == 0) return 0.0;

        return abs(((float) $kpi->current_value - $mean) / $stddev);
    }

    private function buildAlertMessage(Alert $alert, Kpi $kpi, float $value): string
    {
        $direction = match ($alert->condition) {
            'above' => 'exceeded',
            'below' => 'dropped below',
            'change_percent' => 'changed by',
            'anomaly' => 'shows anomalous behavior with score',
            default => 'triggered at',
        };

        $formattedValue = match ($kpi->unit) {
            'currency' => '$' . number_format($value, 2),
            'percent' => number_format($value, 2) . '%',
            default => number_format($value, 2),
        };

        return "{$kpi->name} {$direction} {$formattedValue} (threshold: {$alert->threshold})";
    }

    private function notify(Alert $alert, AlertHistory $history): void
    {
        foreach ($alert->channels ?? [] as $channel) {
            match ($channel) {
                'email' => $this->notifyEmail($alert, $history),
                'slack' => $this->notifySlack($alert, $history),
                'webhook' => $this->notifyWebhook($alert, $history),
                'push' => $this->notifyPush($alert, $history),
                default => Log::debug("[AlertService] Unknown notification channel: {$channel}"),
            };
        }
    }

    private function notifyEmail(Alert $alert, AlertHistory $history): void
    {
        foreach ($alert->recipients ?? [] as $recipient) {
            try {
                \Illuminate\Support\Facades\Mail::raw($history->message, function ($msg) use ($recipient, $alert) {
                    $msg->to($recipient)->subject("[Ecom360 Alert] {$alert->name}");
                });
            } catch (\Throwable $e) {
                Log::error("[AlertService] Email notification failed: {$e->getMessage()}");
            }
        }
    }

    private function notifySlack(Alert $alert, AlertHistory $history): void
    {
        $webhookUrl = $alert->kpi?->tenant?->settings?->where('key', 'slack_webhook_url')->first();
        if (!$webhookUrl) return;

        try {
            \Illuminate\Support\Facades\Http::post($webhookUrl->value, [
                'text' => ":warning: *{$alert->name}*\n{$history->message}",
            ]);
        } catch (\Throwable $e) {
            Log::error("[AlertService] Slack notification failed: {$e->getMessage()}");
        }
    }

    private function notifyWebhook(Alert $alert, AlertHistory $history): void
    {
        $url = collect($alert->recipients ?? [])->first(fn($r) => str_starts_with($r, 'http'));
        if (!$url) return;

        try {
            \Illuminate\Support\Facades\Http::post($url, [
                'alert_id' => $alert->id,
                'alert_name' => $alert->name,
                'message' => $history->message,
                'triggered_value' => $history->triggered_value,
                'threshold' => $history->threshold_value,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error("[AlertService] Webhook notification failed: {$e->getMessage()}");
        }
    }

    private function notifyPush(Alert $alert, AlertHistory $history): void
    {
        // Delegate to Marketing push provider if available
        IntegrationEvent::dispatch('BusinessIntelligence', 'push_alert', [
            'tenant_id' => $alert->tenant_id,
            'title' => "Alert: {$alert->name}",
            'body' => $history->message,
            'recipients' => $alert->recipients,
        ]);
    }
}
