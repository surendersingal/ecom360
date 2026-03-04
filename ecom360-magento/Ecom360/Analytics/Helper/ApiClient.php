<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Helper;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for communicating with the Ecom360 platform API.
 *
 * Supports both public SDK endpoints (X-Ecom360-Key auth) and
 * authenticated server-to-server endpoints (Bearer token auth).
 */
class ApiClient
{
    private Config $config;
    private CurlFactory $curlFactory;
    private Json $json;
    private LoggerInterface $logger;
    private ProductMetadataInterface $productMetadata;

    /** @var int HTTP timeout in seconds */
    private const TIMEOUT = 30;

    /** @var int Queue consumer timeout (generous — runs in cron, not in request) */
    private const ASYNC_TIMEOUT = 10;

    public function __construct(
        Config $config,
        CurlFactory $curlFactory,
        Json $json,
        LoggerInterface $logger,
        ProductMetadataInterface $productMetadata
    ) {
        $this->config = $config;
        $this->curlFactory = $curlFactory;
        $this->json = $json;
        $this->logger = $logger;
        $this->productMetadata = $productMetadata;
    }

    /* ══════════════════════════ Public SDK endpoints ══════════════════ */

    /**
     * Send a single tracking event to POST /api/v1/collect.
     */
    public function sendEvent(string $eventType, array $metadata = [], ?array $customer = null, ?int $storeId = null): bool
    {
        $payload = $this->buildEventPayload($eventType, $metadata, $customer, $storeId);
        return $this->postPublic($this->config->getCollectEndpoint($storeId), $payload, $storeId, true);
    }

    /**
     * Send a batch of tracking events to POST /api/v1/collect/batch.
     */
    public function sendBatch(array $events, ?int $storeId = null): bool
    {
        return $this->postPublic(
            $this->config->getBatchEndpoint($storeId),
            ['events' => $events],
            $storeId
        );
    }

    /**
     * Test the connection to the Ecom360 platform.
     *
     * @return array{success: bool, message: string, tenant?: string}
     */
    public function testConnection(?int $storeId = null): array
    {
        $payload = [
            'event_type' => 'connection_test',
            'url'        => $this->config->getServerUrl($storeId),
            'session_id' => 'magento_test_' . bin2hex(random_bytes(8)),
            'metadata'   => [
                'platform'        => 'magento2',
                'module_version'  => '1.0.0',
                'magento_version' => $this->getMagentoVersion(),
                'php_version'     => PHP_VERSION,
            ],
        ];

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT);
            $curl->setHeaders([
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'X-Ecom360-Key' => $this->config->getApiKey($storeId),
            ]);
            $curl->post($this->config->getCollectEndpoint($storeId), $this->json->serialize($payload));

            $status = $curl->getStatus();
            $body = $this->json->unserialize($curl->getBody());

            if ($status >= 200 && $status < 300) {
                return [
                    'success' => true,
                    'message' => 'Connected successfully!',
                    'tenant'  => $body['tenant'] ?? null,
                ];
            }

            return [
                'success' => false,
                'message' => sprintf('Server returned %d: %s', $status, $body['message'] ?? 'Unknown error'),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 test connection failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /* ══════════════════════════ Server-to-Server Sync ═════════════════ */

    /**
     * POST sync data to an authenticated endpoint.
     *
     * @param string $path    Relative API path (e.g. '/api/v1/sync/products')
     * @param array  $payload Request body
     */
    public function syncData(string $path, array $payload, ?int $storeId = null): array
    {
        $url = $this->config->getServerUrl($storeId) . $path;

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT);
            $curl->setHeaders([
                'Content-Type'      => 'application/json',
                'Accept'            => 'application/json',
                'X-Ecom360-Key'     => $this->config->getApiKey($storeId),
                'X-Ecom360-Secret'  => $this->config->getSecretKey($storeId),
            ]);
            $curl->post($url, $this->json->serialize($payload));

            $status = $curl->getStatus();
            $body = [];
            try {
                $body = $this->json->unserialize($curl->getBody());
            } catch (\Exception $e) {
                // Non-JSON response
            }

            if ($status < 200 || $status >= 300) {
                $this->logger->warning('Ecom360 sync non-OK [' . $path . ']: HTTP ' . $status, ['body' => $curl->getBody()]);
            }

            return [
                'success'     => $status >= 200 && $status < 300,
                'status_code' => $status,
                'data'        => $body,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 sync failed [' . $path . ']: ' . $e->getMessage());
            return [
                'success'     => false,
                'status_code' => 0,
                'data'        => ['message' => $e->getMessage()],
            ];
        }
    }

    /* ══════════════════════════ Push Notifications ════════════════════ */

    /**
     * Send a push notification via Firebase Cloud Messaging.
     */
    public function sendFirebasePush(string $token, string $title, string $body, array $data = [], ?int $storeId = null): bool
    {
        $serverKey = $this->config->getFirebaseApiKey($storeId);
        if (!$serverKey) {
            return false;
        }

        $payload = [
            'to' => $token,
            'notification' => [
                'title' => $title,
                'body'  => $body,
                'icon'  => '/favicon.ico',
            ],
            'data' => $data,
        ];

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT);
            $curl->setHeaders([
                'Content-Type'  => 'application/json',
                'Authorization' => 'key=' . $serverKey,
            ]);
            $curl->post('https://fcm.googleapis.com/fcm/send', $this->json->serialize($payload));

            return $curl->getStatus() === 200;
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 Firebase push failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a push notification via OneSignal.
     */
    public function sendOneSignalPush(string $playerId, string $title, string $body, array $data = [], ?int $storeId = null): bool
    {
        $appId = $this->config->getOneSignalAppId($storeId);
        $apiKey = $this->config->getOneSignalApiKey($storeId);
        if (!$appId || !$apiKey) {
            return false;
        }

        $payload = [
            'app_id'             => $appId,
            'include_player_ids' => [$playerId],
            'headings'           => ['en' => $title],
            'contents'           => ['en' => $body],
            'data'               => $data,
        ];

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT);
            $curl->setHeaders([
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . $apiKey,
            ]);
            $curl->post('https://onesignal.com/api/v1/notifications', $this->json->serialize($payload));

            return $curl->getStatus() === 200;
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 OneSignal push failed: ' . $e->getMessage());
            return false;
        }
    }

    /* ══════════════════════════ Queue consumer helpers ════════════════ */

    /* ══════════════════════════ Chatbot API ═══════════════════════════ */

    /**
     * Send a message to the Ecom360 chatbot.
     */
    public function sendChatMessage(string $sessionId, string $message, array $context = [], ?int $storeId = null): array
    {
        $url = $this->config->getServerUrl($storeId) . '/api/v1/chatbot/send';
        $payload = [
            'session_id' => $sessionId,
            'message'    => $message,
            'page_url'   => $context['page_url'] ?? '',
        ];

        return $this->postAndDecode($url, $payload, $storeId);
    }

    /**
     * Report a rage-click event to the chatbot.
     */
    public function reportRageClick(string $sessionId, string $element, string $pageUrl, ?int $storeId = null): array
    {
        $url = $this->config->getServerUrl($storeId) . '/api/v1/chatbot/rage-click';
        return $this->postAndDecode($url, [
            'session_id' => $sessionId,
            'element'    => $element,
            'page_url'   => $pageUrl,
        ], $storeId);
    }

    /* ══════════════════════════ AI Search API ═════════════════════════ */

    /**
     * Perform an AI-powered search.
     */
    public function aiSearch(string $query, array $filters = [], int $limit = 20, ?int $storeId = null): array
    {
        $params = http_build_query(array_merge(['q' => $query, 'limit' => $limit], $filters));
        $url = $this->config->getServerUrl($storeId) . '/api/v1/search/search?' . $params;

        return $this->getAndDecode($url, $storeId);
    }

    /**
     * Get search suggestions.
     */
    public function aiSearchSuggest(string $query, int $limit = 6, ?int $storeId = null): array
    {
        $url = $this->config->getServerUrl($storeId) . '/api/v1/search/suggest?q=' . urlencode($query) . '&limit=' . $limit;
        return $this->getAndDecode($url, $storeId);
    }

    /* ══════════════════════════ Interventions API ═════════════════════ */

    /**
     * Fetch pending interventions for a session.
     */
    public function fetchInterventions(string $sessionId, ?int $storeId = null): array
    {
        $url = $this->config->getServerUrl($storeId) . '/api/v1/interventions/poll?session_id=' . urlencode($sessionId);
        return $this->getAndDecode($url, $storeId);
    }

    /* ══════════════════════════ Queue consumer helpers ════════════════ */

    /**
     * Build a complete event payload — public wrapper used by ProcessEventQueueCron.
     */
    public function buildEventPayloadPublic(string $eventType, array $metadata, ?array $customer, ?int $storeId): array
    {
        return $this->buildEventPayload($eventType, $metadata, $customer, $storeId);
    }

    /**
     * Send a pre-built event payload directly (used by cron consumer for single events).
     */
    public function sendEventDirect(array $payload, ?int $storeId = null): bool
    {
        return $this->postPublic($this->config->getCollectEndpoint($storeId), $payload, $storeId);
    }

    /* ══════════════════════════ Internal helpers ══════════════════════ */

    /**
     * POST and return decoded JSON response.
     */
    private function postAndDecode(string $url, array $payload, ?int $storeId = null): array
    {
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT);
            $curl->setHeaders([
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'X-Ecom360-Key' => $this->config->getApiKey($storeId),
            ]);
            $curl->post($url, $this->json->serialize($payload));

            return [
                'success'     => $curl->getStatus() >= 200 && $curl->getStatus() < 300,
                'status_code' => $curl->getStatus(),
                'data'        => $this->safeDecode($curl->getBody()),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 API POST failed [' . $url . ']: ' . $e->getMessage());
            return ['success' => false, 'status_code' => 0, 'data' => ['message' => $e->getMessage()]];
        }
    }

    /**
     * GET and return decoded JSON response.
     */
    private function getAndDecode(string $url, ?int $storeId = null): array
    {
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT);
            $curl->setHeaders([
                'Accept'        => 'application/json',
                'X-Ecom360-Key' => $this->config->getApiKey($storeId),
            ]);
            $curl->get($url);

            return [
                'success'     => $curl->getStatus() >= 200 && $curl->getStatus() < 300,
                'status_code' => $curl->getStatus(),
                'data'        => $this->safeDecode($curl->getBody()),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 API GET failed [' . $url . ']: ' . $e->getMessage());
            return ['success' => false, 'status_code' => 0, 'data' => ['message' => $e->getMessage()]];
        }
    }

    private function safeDecode(string $body): array
    {
        try {
            return $this->json->unserialize($body) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Build an event payload matching PublicTrackingRequest validation.
     */
    private function buildEventPayload(string $eventType, array $metadata, ?array $customer, ?int $storeId): array
    {
        $payload = [
            'event_type' => $eventType,
            'url'        => $this->getCurrentUrl(),
            'session_id' => $this->getServerSessionId(),
            'metadata'   => $metadata,
            'timezone'   => date_default_timezone_get(),
            'language'   => $this->getStoreLocale($storeId),
        ];

        if ($customer) {
            $payload['customer_identifier'] = $customer;
        }

        return $payload;
    }

    /**
     * POST to a public SDK endpoint using X-Ecom360-Key auth.
     */
    private function postPublic(string $url, array $payload, ?int $storeId = null, bool $async = false): bool
    {
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout($async ? self::ASYNC_TIMEOUT : self::TIMEOUT);
            $curl->setHeaders([
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'X-Ecom360-Key' => $this->config->getApiKey($storeId),
            ]);
            $curl->post($url, $this->json->serialize($payload));

            $status = $curl->getStatus();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('Ecom360 API returned ' . $status . ' for ' . $url);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            // For async/fire-and-forget calls, timeouts are acceptable
            if ($async) {
                return true;
            }
            $this->logger->error('Ecom360 API call failed [' . $url . ']: ' . $e->getMessage());
            return false;
        }
    }

    private function getServerSessionId(): string
    {
        $sessionId = $_COOKIE['ecom360_sid'] ?? null;
        if ($sessionId) {
            return $sessionId;
        }
        return 'srv_' . bin2hex(random_bytes(16));
    }

    private function getCurrentUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return $scheme . '://' . $host . $uri;
    }

    private function getStoreLocale(?int $storeId): string
    {
        try {
            // Use core config path directly (not through module prefix)
            return (string) ($this->config->scopeConfig->getValue(
                'general/locale/code',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ) ?? 'en_US');
        } catch (\Exception $e) {
            return 'en_US';
        }
    }

    private function getMagentoVersion(): string
    {
        try {
            return $this->productMetadata->getVersion();
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
}
