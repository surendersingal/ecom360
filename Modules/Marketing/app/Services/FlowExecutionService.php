<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Events\IntegrationEvent;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Channels\ChannelManager;
use Modules\Marketing\Models\Channel;
use Modules\Marketing\Models\Contact;
use Modules\Marketing\Models\Flow;
use Modules\Marketing\Models\FlowEdge;
use Modules\Marketing\Models\FlowEnrollment;
use Modules\Marketing\Models\FlowLog;
use Modules\Marketing\Models\FlowNode;
use Modules\Marketing\Models\Message;
use Modules\Marketing\Models\Template;

/**
 * Processes marketing automation flows.
 *
 * Handles enrollment, node execution, branching, delays, conditions,
 * and all 16 node types defined in the flow builder.
 *
 * Node types:
 *   trigger, send_email, send_whatsapp, send_rcs, send_push, send_sms,
 *   delay, condition, split, webhook, update_contact, add_to_list,
 *   remove_from_list, goal, exit
 */
final class FlowExecutionService
{
    public function __construct(
        private readonly ChannelManager $channelManager,
        private readonly VariableResolverService $variableResolver,
        private readonly RulesEngineService $rulesEngine,
        private readonly ContactService $contactService,
    ) {}

    /**
     * Enroll a contact into a flow.
     */
    public function enroll(Flow $flow, Contact $contact, array $context = []): ?FlowEnrollment
    {
        if ($flow->status !== 'active') {
            Log::debug("[FlowEngine] Flow #{$flow->id} is not active, skipping enrollment.");
            return null;
        }

        // Check for duplicate enrollment
        $existing = FlowEnrollment::where('flow_id', $flow->id)
            ->where('contact_id', $contact->id)
            ->whereIn('status', ['active', 'waiting'])
            ->first();

        if ($existing) {
            Log::debug("[FlowEngine] Contact #{$contact->id} already enrolled in flow #{$flow->id}");
            return null;
        }

        // Find the trigger node (entry point)
        $triggerNode = FlowNode::where('flow_id', $flow->id)
            ->where('type', 'trigger')
            ->first();

        if (!$triggerNode) {
            Log::error("[FlowEngine] No trigger node found for flow #{$flow->id}");
            return null;
        }

        $enrollment = FlowEnrollment::create([
            'flow_id' => $flow->id,
            'contact_id' => $contact->id,
            'current_node_id' => $triggerNode->node_id,
            'status' => 'active',
            'context' => $context,
            'entered_at' => now(),
        ]);

        $flow->increment('enrolled_count');

        $this->logStep($enrollment, $triggerNode, 'enrolled');

        // Immediately advance past the trigger node to the first real node
        $this->advanceToNextNode($enrollment, $triggerNode);

        return $enrollment;
    }

    /**
     * Process a single enrollment step. Called by the queue worker.
     */
    public function processStep(FlowEnrollment $enrollment): void
    {
        if (!in_array($enrollment->status, ['active', 'waiting'])) {
            return;
        }

        $flow = $enrollment->flow;
        $currentNode = FlowNode::where('flow_id', $flow->id)
            ->where('node_id', $enrollment->current_node_id)
            ->first();

        if (!$currentNode) {
            $this->completeEnrollment($enrollment, 'error');
            Log::error("[FlowEngine] Node {$enrollment->current_node_id} not found in flow #{$flow->id}");
            return;
        }

        $contact = $enrollment->contact;
        if (!$contact) {
            $this->completeEnrollment($enrollment, 'error');
            return;
        }

        $result = $this->executeNode($currentNode, $enrollment, $contact);

        if ($result === 'wait') {
            // Node requires waiting (delay node), don't advance
            return;
        }

        if ($result === 'exit') {
            $this->completeEnrollment($enrollment, 'completed');
            return;
        }

        if ($result === 'goal_reached') {
            $this->completeEnrollment($enrollment, 'converted');
            $flow->increment('converted_count');
            return;
        }

        // For condition/split nodes, result is the specific edge label to follow
        if (is_string($result) && str_starts_with($result, 'branch:')) {
            $branchLabel = substr($result, 7);
            $this->advanceToNextNode($enrollment, $currentNode, $branchLabel);
        } else {
            // Normal flow: advance to next connected node
            $this->advanceToNextNode($enrollment, $currentNode);
        }
    }

    /**
     * Process all enrollments that are due for their next action.
     * Called periodically by the scheduler.
     */
    public function processDueEnrollments(): int
    {
        $enrollments = FlowEnrollment::where('status', 'waiting')
            ->where('next_action_at', '<=', now())
            ->limit(100)
            ->get();

        $processed = 0;
        foreach ($enrollments as $enrollment) {
            $enrollment->update(['status' => 'active']);
            $this->processStep($enrollment);
            $processed++;
        }

        return $processed;
    }

    /**
     * Prune enrollments stuck in waiting state for more than 7 days.
     * Called by the scheduler every 5 minutes.
     */
    public function pruneStaleEnrollments(): int
    {
        $cutoff = now()->subDays(7);

        $stale = FlowEnrollment::where('status', 'waiting')
            ->where('updated_at', '<', $cutoff)
            ->get();

        $pruned = 0;
        foreach ($stale as $enrollment) {
            $this->completeEnrollment($enrollment, 'expired');
            $pruned++;
        }

        if ($pruned > 0) {
            Log::info("[FlowEngine] Pruned {$pruned} stale enrollments older than 7 days.");
        }

        return $pruned;
    }

    /**
     * Check if a contact has reached a goal in any active flow.
     * Event-driven — called when a qualifying event fires (e.g. purchase, signup).
     */
    public function checkGoals(Contact $contact, string $goalEvent, array $eventData = []): void
    {
        $enrollments = FlowEnrollment::where('contact_id', $contact->id)
            ->whereIn('status', ['active', 'waiting'])
            ->get();

        foreach ($enrollments as $enrollment) {
            // Look for goal nodes in the flow
            $goalNodes = FlowNode::where('flow_id', $enrollment->flow_id)
                ->where('type', 'goal')
                ->get();

            foreach ($goalNodes as $goalNode) {
                $goalConfig = $goalNode->config ?? [];
                $targetEvent = $goalConfig['event'] ?? null;

                if ($targetEvent === $goalEvent) {
                    // Check if conditions match
                    $conditions = $goalConfig['conditions'] ?? null;
                    if ($conditions && !$this->rulesEngine->evaluate($conditions, $eventData)) {
                        continue;
                    }

                    $this->logStep($enrollment, $goalNode, 'goal_reached', $eventData);
                    $this->completeEnrollment($enrollment, 'converted');
                    $enrollment->flow->increment('converted_count');
                }
            }
        }
    }

    /**
     * Handle an event trigger for flow enrollments.
     * Called by the event bus when Analytics fires qualifying events.
     */
    public function handleEventTrigger(string $tenantId, string $eventName, array $eventData): void
    {
        // Find active flows with matching event triggers
        $flows = Flow::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('trigger_type', 'event')
            ->get();

        foreach ($flows as $flow) {
            $triggerConfig = $flow->trigger_config ?? [];
            $targetEvent = $triggerConfig['event'] ?? null;

            if ($targetEvent !== $eventName) continue;

            // Resolve contact from event data
            $email = $eventData['email'] ?? $eventData['customer_email'] ?? null;
            if (!$email) continue;

            $contact = Contact::where('tenant_id', $tenantId)->where('email', $email)->first();
            if (!$contact || $contact->status !== 'subscribed') continue;

            // Check trigger conditions
            $conditions = $triggerConfig['conditions'] ?? null;
            if ($conditions && !$this->rulesEngine->evaluate($conditions, $eventData)) {
                continue;
            }

            $this->enroll($flow, $contact, $eventData);
        }
    }

    // ─── Node Executors ──────────────────────────────────────────────

    private function executeNode(FlowNode $node, FlowEnrollment $enrollment, Contact $contact): string
    {
        $config = $node->config ?? [];

        $result = match ($node->type) {
            'trigger' => 'continue',
            'send_email' => $this->executeSendNode($node, $enrollment, $contact, 'email'),
            'send_whatsapp' => $this->executeSendNode($node, $enrollment, $contact, 'whatsapp'),
            'send_rcs' => $this->executeSendNode($node, $enrollment, $contact, 'rcs'),
            'send_push' => $this->executeSendNode($node, $enrollment, $contact, 'push'),
            'send_sms' => $this->executeSendNode($node, $enrollment, $contact, 'sms'),
            'delay' => $this->executeDelay($node, $enrollment, $config),
            'condition' => $this->executeCondition($node, $enrollment, $contact, $config),
            'split' => $this->executeSplit($node, $config),
            'webhook' => $this->executeWebhook($node, $enrollment, $contact, $config),
            'update_contact' => $this->executeUpdateContact($node, $enrollment, $contact, $config),
            'add_to_list' => $this->executeAddToList($node, $enrollment, $contact, $config),
            'remove_from_list' => $this->executeRemoveFromList($node, $enrollment, $contact, $config),
            'goal' => 'goal_reached',
            'exit' => 'exit',
            default => 'continue',
        };

        $this->logStep($enrollment, $node, $result);
        return $result;
    }

    private function executeSendNode(FlowNode $node, FlowEnrollment $enrollment, Contact $contact, string $channelType): string
    {
        $config = $node->config ?? [];
        $templateId = $config['template_id'] ?? null;

        if (!$templateId) {
            Log::warning("[FlowEngine] No template_id in send node {$node->node_id}");
            return 'continue';
        }

        $template = Template::find($templateId);
        if (!$template) return 'continue';

        $channel = Channel::where('tenant_id', $enrollment->flow->tenant_id)
            ->where('type', $channelType)
            ->where('is_active', true)
            ->first();

        if (!$channel) {
            Log::warning("[FlowEngine] No active {$channelType} channel for tenant");
            return 'continue';
        }

        $provider = $this->channelManager->resolve($channel);
        $variables = $this->variableResolver->resolve($contact->toArray(), [
            'tenant_id' => (string) $enrollment->flow->tenant_id,
            ...(array) ($enrollment->context ?? []),
        ]);
        $rendered = $template->render($variables);

        $to = match ($channelType) {
            'email' => $contact->email,
            'sms', 'whatsapp', 'rcs' => $contact->phone,
            'push' => $contact->custom_fields['push_token'] ?? '',
            default => $contact->email,
        };

        if (empty($to)) return 'continue';

        $message = Message::create([
            'campaign_id' => null,
            'contact_id' => $contact->id,
            'template_id' => $template->id,
            'channel' => $channelType,
            'status' => 'sending',
            'variables_resolved' => $variables,
        ]);

        $result = $provider->send($channel, $message, $rendered, $to);

        $message->update([
            'status' => $result['success'] ? 'sent' : 'failed',
            'external_id' => $result['external_id'],
            'error_message' => $result['error'],
            'sent_at' => $result['success'] ? now() : null,
        ]);

        IntegrationEvent::dispatch('Marketing', 'campaign_message_sent', [
            'tenant_id' => $enrollment->flow->tenant_id,
            'contact_id' => $contact->id,
            'channel' => $channelType,
            'flow_id' => $enrollment->flow_id,
            'node_id' => $node->node_id,
        ]);

        return 'continue';
    }

    private function executeDelay(FlowNode $node, FlowEnrollment $enrollment, array $config): string
    {
        $duration = $config['duration'] ?? 1;
        $unit = $config['unit'] ?? 'hours';

        $nextAction = match ($unit) {
            'minutes' => now()->addMinutes((int) $duration),
            'hours' => now()->addHours((int) $duration),
            'days' => now()->addDays((int) $duration),
            'weeks' => now()->addWeeks((int) $duration),
            default => now()->addHours(1),
        };

        $enrollment->update([
            'status' => 'waiting',
            'next_action_at' => $nextAction,
        ]);

        return 'wait';
    }

    private function executeCondition(FlowNode $node, FlowEnrollment $enrollment, Contact $contact, array $config): string
    {
        $rules = $config['rules'] ?? ['match' => 'all', 'rules' => []];
        $contextData = array_merge(
            $contact->toArray(),
            ['enrollment' => $enrollment->context ?? []],
        );

        $result = $this->rulesEngine->evaluate($rules, $contextData);

        return $result ? 'branch:yes' : 'branch:no';
    }

    private function executeSplit(FlowNode $node, array $config): string
    {
        // Random percentage split
        $splits = $config['splits'] ?? [['label' => 'a', 'percentage' => 50], ['label' => 'b', 'percentage' => 50]];
        $rand = mt_rand(1, 100);
        $cumulative = 0;

        foreach ($splits as $split) {
            $cumulative += (int) ($split['percentage'] ?? 0);
            if ($rand <= $cumulative) {
                return 'branch:' . ($split['label'] ?? 'default');
            }
        }

        return 'branch:' . ($splits[0]['label'] ?? 'a');
    }

    private function executeWebhook(FlowNode $node, FlowEnrollment $enrollment, Contact $contact, array $config): string
    {
        try {
            $url = $config['url'] ?? null;
            if (!$url) return 'continue';

            $method = strtolower($config['method'] ?? 'post');
            $headers = $config['headers'] ?? [];

            $payload = [
                'contact' => $contact->toArray(),
                'flow_id' => $enrollment->flow_id,
                'enrollment_id' => $enrollment->id,
                'context' => $enrollment->context,
                'timestamp' => now()->toIso8601String(),
            ];

            $response = match ($method) {
                'get' => \Illuminate\Support\Facades\Http::withHeaders($headers)->get($url, $payload),
                default => \Illuminate\Support\Facades\Http::withHeaders($headers)->post($url, $payload),
            };

            Log::info("[FlowEngine] Webhook {$method} {$url} → {$response->status()}");
        } catch (\Throwable $e) {
            Log::error("[FlowEngine] Webhook failed: {$e->getMessage()}");
        }

        return 'continue';
    }

    private function executeUpdateContact(FlowNode $node, FlowEnrollment $enrollment, Contact $contact, array $config): string
    {
        $updates = $config['fields'] ?? [];
        if (empty($updates)) return 'continue';

        $allowedFields = ['first_name', 'last_name', 'phone', 'company', 'city', 'country', 'tags', 'custom_fields'];
        $filtered = array_intersect_key($updates, array_flip($allowedFields));

        if (!empty($filtered)) {
            $contact->update($filtered);
        }

        return 'continue';
    }

    private function executeAddToList(FlowNode $node, FlowEnrollment $enrollment, Contact $contact, array $config): string
    {
        $listId = $config['list_id'] ?? null;
        if ($listId) {
            $this->contactService->addToList($contact, (int) $listId);
        }
        return 'continue';
    }

    private function executeRemoveFromList(FlowNode $node, FlowEnrollment $enrollment, Contact $contact, array $config): string
    {
        $listId = $config['list_id'] ?? null;
        if ($listId) {
            $this->contactService->removeFromList($contact, (int) $listId);
        }
        return 'continue';
    }

    // ─── Flow Navigation ─────────────────────────────────────────────

    private function advanceToNextNode(FlowEnrollment $enrollment, FlowNode $currentNode, ?string $branchLabel = null): void
    {
        $edgeQuery = FlowEdge::where('flow_id', $currentNode->flow_id)
            ->where('source_node_id', $currentNode->node_id);

        if ($branchLabel) {
            $edgeQuery->where('label', $branchLabel);
        }

        $edge = $edgeQuery->first();

        if (!$edge) {
            // No outgoing edge — end of flow
            $this->completeEnrollment($enrollment, 'completed');
            return;
        }

        $enrollment->update(['current_node_id' => $edge->target_node_id]);

        // Immediately process the next node (unless it's a delay)
        $this->processStep($enrollment);
    }

    private function completeEnrollment(FlowEnrollment $enrollment, string $status): void
    {
        $enrollment->update([
            'status' => $status,
            'completed_at' => now(),
        ]);

        if ($status === 'completed') {
            $enrollment->flow->increment('completed_count');
        }
    }

    private function logStep(FlowEnrollment $enrollment, FlowNode $node, string $action, array $data = []): void
    {
        FlowLog::create([
            'enrollment_id' => $enrollment->id,
            'node_id' => $node->node_id,
            'node_type' => $node->type,
            'action' => $action,
            'data' => $data,
        ]);
    }
}
