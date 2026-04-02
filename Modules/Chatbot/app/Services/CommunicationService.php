<?php
declare(strict_types=1);

namespace Modules\Chatbot\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

/**
 * CommunicationService — Omni-channel communication engine for chatbot.
 *
 * Handles sending notifications across multiple channels:
 *   - Email (via Laravel Mail)
 *   - WhatsApp (via WhatsApp Business API / Twilio)
 *   - Push Notifications (via web push / FCM)
 *   - SMS (via Twilio/Vonage)
 *
 * All communications are logged to MongoDB for audit trail.
 */
class CommunicationService
{
    /**
     * Send a notification through the specified channel.
     */
    public function sendNotification(int $tenantId, array $params): array
    {
        $type    = $params['type'] ?? 'general';
        $channel = $params['channel'] ?? 'email';
        $to      = $params['to'] ?? null;
        $data    = $params['data'] ?? [];

        if (!$to) {
            return ['success' => false, 'error' => 'No recipient specified.'];
        }

        $result = match ($channel) {
            'email'     => $this->sendEmail($tenantId, $to, $type, $data),
            'whatsapp'  => $this->sendWhatsApp($tenantId, $to, $type, $data),
            'push'      => $this->sendPushNotification($tenantId, $to, $type, $data),
            'sms'       => $this->sendSms($tenantId, $to, $type, $data),
            default     => ['success' => false, 'error' => "Unknown channel: {$channel}"],
        };

        // Log communication
        $this->logCommunication($tenantId, [
            'type'       => $type,
            'channel'    => $channel,
            'to'         => $to,
            'data'       => $data,
            'result'     => $result,
            'created_at' => now()->toDateTimeString(),
        ]);

        return $result;
    }

    /**
     * Process form submission and trigger appropriate communications.
     */
    public function processFormSubmission(int $tenantId, array $params): array
    {
        $formId        = $params['form_id'] ?? 'unknown';
        $formData      = $params['form_data'] ?? [];
        $communications = $params['communications'] ?? [];
        $conversationId = $params['conversation_id'] ?? null;
        $submitAction  = $params['submit_action'] ?? '';

        // Store form submission
        $submissionId = $this->storeFormSubmission($tenantId, $formId, $formData, $conversationId);

        // Process communications
        $commResults = [];
        $settings = $this->loadTenantSettings($tenantId);

        foreach ($communications as $commType) {
            try {
                $commResult = match ($commType) {
                    'email_confirmation' => $this->sendFormConfirmationEmail($tenantId, $formId, $formData, $settings),
                    'email_support_team' => $this->sendSupportTeamNotification($tenantId, $formId, $formData, $submitAction, $settings),
                    'agent_notification' => $this->sendAgentNotification($tenantId, $formData, $conversationId, $settings),
                    'push_notification'  => $this->sendPushNotification(
                        $tenantId,
                        $settings['chatbot_support_email'] ?? 'support@ecom360.com',
                        'form_submission',
                        ['form_id' => $formId, 'data' => $formData]
                    ),
                    'whatsapp_confirmation' => $this->sendWhatsApp(
                        $tenantId,
                        $formData['phone'] ?? '',
                        'form_confirmation',
                        ['form_id' => $formId, 'data' => $formData]
                    ),
                    default => ['success' => false, 'error' => "Unknown communication type: {$commType}"],
                };
                $commResults[$commType] = $commResult;
            } catch (\Throwable $e) {
                Log::error("CommunicationService: Failed {$commType} for form {$formId}: {$e->getMessage()}");
                $commResults[$commType] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Generate response message based on form type
        $responseMessage = $this->getFormSubmissionResponse($formId, $formData, $submitAction);

        return [
            'success'        => true,
            'submission_id'  => $submissionId,
            'form_id'        => $formId,
            'message'        => $responseMessage,
            'communications' => $commResults,
        ];
    }

    /**
     * Send multi-channel communication — fan out to multiple channels at once.
     */
    public function sendMultiChannel(int $tenantId, array $channels, string $type, array $data): array
    {
        $results = [];
        foreach ($channels as $channel) {
            $to = match ($channel) {
                'email'    => $data['email'] ?? null,
                'whatsapp' => $data['phone'] ?? null,
                'sms'      => $data['phone'] ?? null,
                'push'     => $data['email'] ?? $data['user_id'] ?? null,
                default    => null,
            };
            if ($to) {
                $results[$channel] = $this->sendNotification($tenantId, [
                    'type'    => $type,
                    'channel' => $channel,
                    'to'      => $to,
                    'data'    => $data,
                ]);
            }
        }
        return ['success' => true, 'results' => $results];
    }

    /**
     * Get communication history for a conversation or customer.
     */
    public function getCommunicationHistory(int $tenantId, array $filters = []): array
    {
        try {
            $query = DB::connection('mongodb')
                ->table('chatbot_communications')
                ->where('tenant_id', $tenantId);

            if (!empty($filters['conversation_id'])) {
                $query->where('conversation_id', $filters['conversation_id']);
            }
            if (!empty($filters['email'])) {
                $query->where('to', $filters['email']);
            }
            if (!empty($filters['channel'])) {
                $query->where('channel', $filters['channel']);
            }

            $communications = $query->orderBy('created_at', 'desc')
                ->limit($filters['limit'] ?? 50)
                ->get();

            return [
                'success'        => true,
                'communications' => $communications->toArray(),
                'count'          => $communications->count(),
            ];
        } catch (\Throwable $e) {
            Log::error("CommunicationService::getHistory error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Channel Implementations ─────────────────────────────────────

    /**
     * Send email via Laravel Mail.
     */
    private function sendEmail(int $tenantId, string $to, string $type, array $data): array
    {
        try {
            $subject = $this->getEmailSubject($type, $data);
            $body    = $this->getEmailBody($type, $data);

            Mail::raw($body, function ($mail) use ($to, $subject, $data) {
                $mail->to($to)
                    ->subject($subject);

                if (!empty($data['reply_to'])) {
                    $mail->replyTo($data['reply_to']);
                }
            });

            return ['success' => true, 'channel' => 'email', 'to' => $to, 'subject' => $subject];
        } catch (\Throwable $e) {
            Log::error("CommunicationService::sendEmail error: {$e->getMessage()}", ['to' => $to, 'type' => $type]);
            return ['success' => false, 'channel' => 'email', 'error' => $e->getMessage()];
        }
    }

    /**
     * Send WhatsApp message via WhatsApp Business API.
     */
    private function sendWhatsApp(int $tenantId, string $to, string $type, array $data): array
    {
        try {
            if (empty($to)) {
                return ['success' => false, 'channel' => 'whatsapp', 'error' => 'No phone number provided.'];
            }

            $settings = $this->loadTenantSettings($tenantId);
            $apiKey   = $settings['whatsapp_api_key'] ?? null;
            $apiUrl   = $settings['whatsapp_api_url'] ?? 'https://graph.facebook.com/v18.0';
            $phoneId  = $settings['whatsapp_phone_id'] ?? null;

            if (!$apiKey || !$phoneId) {
                // WhatsApp not configured — log and return info
                Log::info("CommunicationService: WhatsApp not configured for tenant {$tenantId}", ['to' => $to, 'type' => $type]);
                return [
                    'success' => false,
                    'channel' => 'whatsapp',
                    'error'   => 'WhatsApp not configured for this store.',
                    'queued'  => true, // Mark as requiring manual follow-up
                ];
            }

            $message = $this->getWhatsAppMessage($type, $data);

            // Send via WhatsApp Business API
            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->post("{$apiUrl}/{$phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => preg_replace('/[^0-9]/', '', $to),
                    'type'              => 'text',
                    'text'              => ['body' => $message],
                ]);

            if ($response->successful()) {
                return ['success' => true, 'channel' => 'whatsapp', 'to' => $to, 'message_id' => $response->json('messages.0.id')];
            }

            return ['success' => false, 'channel' => 'whatsapp', 'error' => $response->body()];
        } catch (\Throwable $e) {
            Log::error("CommunicationService::sendWhatsApp error: {$e->getMessage()}", ['to' => $to]);
            return ['success' => false, 'channel' => 'whatsapp', 'error' => $e->getMessage()];
        }
    }

    /**
     * Send Push Notification via FCM or web push.
     */
    private function sendPushNotification(int $tenantId, string $to, string $type, array $data): array
    {
        try {
            $settings = $this->loadTenantSettings($tenantId);
            $fcmKey   = $settings['fcm_server_key'] ?? null;

            $title = $this->getPushTitle($type, $data);
            $body  = $this->getPushBody($type, $data);

            if (!$fcmKey) {
                // Store push notification for retrieval via polling
                DB::connection('mongodb')
                    ->table('chatbot_push_queue')
                    ->insert([
                        'tenant_id'  => $tenantId,
                        'to'         => $to,
                        'type'       => $type,
                        'title'      => $title,
                        'body'       => $body,
                        'data'       => $data,
                        'read'       => false,
                        'created_at' => now()->toDateTimeString(),
                    ]);

                return ['success' => true, 'channel' => 'push', 'queued' => true, 'message' => 'Queued for delivery'];
            }

            // Send via FCM
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => "key={$fcmKey}",
                'Content-Type'  => 'application/json',
            ])->post('https://fcm.googleapis.com/fcm/send', [
                'to'           => $to,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                    'icon'  => '/images/chatbot-icon.png',
                ],
                'data' => $data,
            ]);

            return [
                'success' => $response->successful(),
                'channel' => 'push',
                'to'      => $to,
            ];
        } catch (\Throwable $e) {
            Log::error("CommunicationService::sendPush error: {$e->getMessage()}");
            return ['success' => false, 'channel' => 'push', 'error' => $e->getMessage()];
        }
    }

    /**
     * Send SMS via Twilio/Vonage.
     */
    private function sendSms(int $tenantId, string $to, string $type, array $data): array
    {
        try {
            if (empty($to)) {
                return ['success' => false, 'channel' => 'sms', 'error' => 'No phone number provided.'];
            }

            $settings  = $this->loadTenantSettings($tenantId);
            $twilioSid = $settings['twilio_sid'] ?? null;
            $twilioAuth = $settings['twilio_auth_token'] ?? null;
            $twilioFrom = $settings['twilio_from_number'] ?? null;

            if (!$twilioSid || !$twilioAuth || !$twilioFrom) {
                Log::info("CommunicationService: SMS not configured for tenant {$tenantId}");
                return ['success' => false, 'channel' => 'sms', 'error' => 'SMS not configured.', 'queued' => true];
            }

            $message = $this->getSmsMessage($type, $data);

            $response = \Illuminate\Support\Facades\Http::asForm()
                ->withBasicAuth($twilioSid, $twilioAuth)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$twilioSid}/Messages.json", [
                    'From' => $twilioFrom,
                    'To'   => $to,
                    'Body' => $message,
                ]);

            return [
                'success' => $response->successful(),
                'channel' => 'sms',
                'to'      => $to,
                'sid'     => $response->json('sid'),
            ];
        } catch (\Throwable $e) {
            Log::error("CommunicationService::sendSms error: {$e->getMessage()}");
            return ['success' => false, 'channel' => 'sms', 'error' => $e->getMessage()];
        }
    }

    // ── Form Submission Processing ──────────────────────────────────

    /**
     * Store form submission in MongoDB.
     */
    private function storeFormSubmission(int $tenantId, string $formId, array $formData, ?string $conversationId): string
    {
        $id = DB::connection('mongodb')
            ->table('chatbot_form_submissions')
            ->insertGetId([
                'tenant_id'       => $tenantId,
                'form_id'         => $formId,
                'conversation_id' => $conversationId,
                'data'            => $formData,
                'status'          => 'new',
                'created_at'      => now()->toDateTimeString(),
                'updated_at'      => now()->toDateTimeString(),
            ]);

        return (string) $id;
    }

    /**
     * Send confirmation email to the customer who submitted a form.
     */
    private function sendFormConfirmationEmail(int $tenantId, string $formId, array $formData, array $settings): array
    {
        $email = $formData['email'] ?? null;
        if (!$email) {
            return ['success' => false, 'error' => 'No email in form data.'];
        }

        $storeName = $settings['chatbot_store_name'] ?? 'Our Store';
        $subject   = $this->getFormConfirmationSubject($formId, $storeName);
        $body      = $this->getFormConfirmationBody($formId, $formData, $storeName);

        return $this->sendEmail($tenantId, $email, 'form_confirmation', [
            'subject' => $subject,
            'body'    => $body,
        ]);
    }

    /**
     * Send notification to support team about a form submission.
     */
    private function sendSupportTeamNotification(int $tenantId, string $formId, array $formData, string $submitAction, array $settings): array
    {
        $supportEmail = $settings['chatbot_support_email'] ?? 'support@ecom360.com';
        $storeName    = $settings['chatbot_store_name'] ?? 'Ecom360 Store';
        $customerName = $formData['name'] ?? 'Anonymous';
        $customerEmail = $formData['email'] ?? 'not provided';

        $subject = "[{$storeName}] New {$this->formatFormId($formId)} from {$customerName}";

        $bodyLines = ["New form submission received:", ""];
        $bodyLines[] = "Form: {$this->formatFormId($formId)}";
        $bodyLines[] = "Action: {$submitAction}";
        $bodyLines[] = "Customer: {$customerName} ({$customerEmail})";
        $bodyLines[] = "Time: " . now()->format('Y-m-d H:i:s');
        $bodyLines[] = "";
        $bodyLines[] = "--- Form Data ---";
        foreach ($formData as $key => $value) {
            $bodyLines[] = ucfirst(str_replace('_', ' ', $key)) . ": " . (is_string($value) ? $value : json_encode($value));
        }
        $bodyLines[] = "";
        $bodyLines[] = "Please review and respond to the customer.";

        return $this->sendEmail($tenantId, $supportEmail, 'support_notification', [
            'subject'    => $subject,
            'body'       => implode("\n", $bodyLines),
            'reply_to'   => $customerEmail,
        ]);
    }

    /**
     * Send agent notification for escalation.
     */
    private function sendAgentNotification(int $tenantId, array $formData, ?string $conversationId, array $settings): array
    {
        $supportEmail = $settings['chatbot_support_email'] ?? 'support@ecom360.com';
        $storeName    = $settings['chatbot_store_name'] ?? 'Ecom360 Store';

        $subject = "[URGENT] [{$storeName}] Customer Escalation Request";
        $body = "A customer has requested to speak with a human agent.\n\n" .
            "Customer: " . ($formData['name'] ?? 'Unknown') . "\n" .
            "Email: " . ($formData['email'] ?? 'Not provided') . "\n" .
            "Phone: " . ($formData['phone'] ?? 'Not provided') . "\n" .
            "Preferred Contact: " . ($formData['preferred_channel'] ?? 'Email') . "\n" .
            "Reason: " . ($formData['reason'] ?? 'Not specified') . "\n\n" .
            "Conversation ID: " . ($conversationId ?? 'N/A') . "\n" .
            "Time: " . now()->format('Y-m-d H:i:s') . "\n\n" .
            "Please respond to this customer as soon as possible.";

        return $this->sendEmail($tenantId, $supportEmail, 'escalation', [
            'subject'  => $subject,
            'body'     => $body,
            'reply_to' => $formData['email'] ?? null,
        ]);
    }

    // ── Message Templates ───────────────────────────────────────────

    private function getEmailSubject(string $type, array $data): string
    {
        return $data['subject'] ?? match ($type) {
            'escalation'        => 'Your support request has been received',
            'form_confirmation' => 'We received your submission',
            'order_update'      => 'Update on your order',
            'complaint_ack'     => 'We received your complaint',
            default             => 'Notification from our store',
        };
    }

    private function getEmailBody(string $type, array $data): string
    {
        return $data['body'] ?? match ($type) {
            'escalation'   => "Thank you for reaching out. A support agent will contact you shortly.\n\nYour conversation reference: " . ($data['conversation_id'] ?? 'N/A'),
            'order_update' => 'Your order #' . ($data['order_id'] ?? '') . ' status: ' . ($data['status'] ?? 'Updated'),
            default        => "Thank you. We've received your request and will get back to you soon.",
        };
    }

    private function getWhatsAppMessage(string $type, array $data): string
    {
        return match ($type) {
            'escalation'        => "Hi! Your support request has been received. A team member will contact you shortly. Reference: " . ($data['conversation_id'] ?? ''),
            'form_confirmation' => "Thank you for your submission! We've received your information and will follow up soon.",
            'order_update'      => 'Order #' . ($data['order_id'] ?? '') . ' update: ' . ($data['status'] ?? 'Processing'),
            default             => "Thank you! We've received your request. Our team will follow up soon.",
        };
    }

    private function getPushTitle(string $type, array $data): string
    {
        return match ($type) {
            'escalation'       => '🔔 Customer Escalation',
            'form_submission'  => '📋 New Form Submission',
            'complaint'        => '⚠️ Customer Complaint',
            'order_update'     => '📦 Order Update',
            default            => '🔔 New Notification',
        };
    }

    private function getPushBody(string $type, array $data): string
    {
        return match ($type) {
            'escalation'      => 'A customer has requested to speak with an agent.',
            'form_submission' => 'New ' . ($data['form_id'] ?? 'form') . ' submission received.',
            'complaint'       => 'A customer has filed a complaint. Please review.',
            default           => 'You have a new notification.',
        };
    }

    private function getSmsMessage(string $type, array $data): string
    {
        return match ($type) {
            'escalation'        => "Your support request has been received. An agent will contact you shortly.",
            'form_confirmation' => "Thank you! We received your submission and will follow up soon.",
            'order_update'      => 'Order #' . ($data['order_id'] ?? '') . ': ' . ($data['status'] ?? 'Updated'),
            default             => "Thank you for contacting us. We'll get back to you soon.",
        };
    }

    private function getFormConfirmationSubject(string $formId, string $storeName): string
    {
        return match ($formId) {
            'escalation_form'       => "[{$storeName}] We've received your support request",
            'complaint_form'        => "[{$storeName}] Your complaint has been registered",
            'return_form'           => "[{$storeName}] Return request received",
            'payment_dispute_form'  => "[{$storeName}] Payment issue reported",
            'callback_form'         => "[{$storeName}] Callback request confirmed",
            'contact_form'          => "[{$storeName}] Thank you for contacting us",
            default                 => "[{$storeName}] We received your submission",
        };
    }

    private function getFormConfirmationBody(string $formId, array $formData, string $storeName): string
    {
        $name = $formData['name'] ?? 'Customer';
        $base = "Dear {$name},\n\nThank you for reaching out to {$storeName}.\n\n";

        $details = match ($formId) {
            'escalation_form'   => "We've received your support request. A member of our team will contact you via your preferred method shortly.\n\nYour message: " . ($formData['reason'] ?? ''),
            'complaint_form'    => "We take your complaint very seriously. Our team will review the issue and respond within 24-48 hours.\n\nIssue type: " . ($formData['issue_type'] ?? 'General') . "\nOrder: " . ($formData['order_id'] ?? 'N/A'),
            'return_form'       => "Your return request has been submitted. We'll send you return shipping instructions shortly.\n\nOrder: " . ($formData['order_id'] ?? 'N/A'),
            'payment_dispute_form' => "We've received your payment dispute report. Our billing team will investigate and respond within 2-3 business days.\n\nOrder: " . ($formData['order_id'] ?? 'N/A'),
            default             => "We've received your information and will follow up soon.",
        };

        return $base . $details . "\n\nBest regards,\n{$storeName} Support Team";
    }

    private function getFormSubmissionResponse(string $formId, array $formData, string $submitAction): string
    {
        $name = $formData['name'] ?? 'there';

        return match ($formId) {
            'escalation_form'           => "Thank you, {$name}! 🙏 Your request has been submitted. A support agent will contact you via " . ($formData['preferred_channel'] ?? 'email') . " shortly. You'll also receive a confirmation email.",
            'complaint_form'            => "Thank you for sharing your feedback, {$name}. Your complaint has been registered and our team will review it within 24-48 hours. You'll receive updates via email.",
            'account_deletion_form'     => "Your account deletion request has been submitted. Our team will process it within 5-7 business days. You'll receive a confirmation email.",
            'payment_dispute_form'      => "Your payment issue has been reported. Our billing team will investigate and respond within 2-3 business days.",
            'gift_card_balance_form'    => "We're checking your gift card balance. You'll receive the details shortly.",
            'subscription_cancel_form'  => "Your subscription change request has been submitted. We'll process it and send a confirmation email.",
            'callback_form'             => "We've scheduled your callback! One of our team members will call you at your preferred time.",
            'contact_form'              => "Thank you for reaching out, {$name}! We've received your message and will respond within 24 hours.",
            default                     => "Thank you! Your submission has been received. We'll follow up soon.",
        };
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function formatFormId(string $formId): string
    {
        return ucwords(str_replace(['_form', '_'], ['', ' '], $formId));
    }

    private function loadTenantSettings(int $tenantId): array
    {
        return Cache::remember("tenant_settings:{$tenantId}:chatbot", 3600, function () use ($tenantId) {
            return \App\Models\TenantSetting::where('tenant_id', $tenantId)
                ->where('module', 'chatbot')
                ->pluck('value', 'key')
                ->toArray();
        });
    }

    private function logCommunication(int $tenantId, array $data): void
    {
        try {
            DB::connection('mongodb')
                ->table('chatbot_communications')
                ->insert(array_merge(['tenant_id' => $tenantId], $data));
        } catch (\Throwable $e) {
            Log::error("CommunicationService: Failed to log communication: {$e->getMessage()}");
        }
    }
}
