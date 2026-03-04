<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Cron;

use Ecom360\Analytics\Helper\ApiClient;
use Ecom360\Analytics\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Polls the Ecom360 platform for pending interventions (coupon offers,
 * chatbot prompts, redirect instructions, etc.) and stores them locally
 * so the storefront JS poller can serve them instantly.
 *
 * Supports: Exit-intent coupons (UC1), rage-click chatbot (UC2),
 *           free-shipping gamification (UC3), and general interventions.
 *
 * Runs every 2 minutes via crontab.xml.
 */
class FetchInterventionsCron
{
    private Config $config;
    private ApiClient $apiClient;
    private LoggerInterface $logger;

    /** @var string Cache file for pending interventions */
    private const CACHE_DIR = BP . '/var/cache/ecom360/';

    public function __construct(
        Config $config,
        ApiClient $apiClient,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        if (!$this->config->isFlag('features/interventions_enabled')) {
            return;
        }

        try {
            $result = $this->apiClient->syncData('/api/v1/interventions/pending', [
                'platform'  => 'magento2',
                'store_url' => $this->config->getServerUrl(),
            ]);

            if (!$result['success']) {
                $this->logger->warning('Ecom360: FetchInterventions failed', [
                    'status' => $result['status_code'],
                ]);
                return;
            }

            $interventions = $result['data']['interventions'] ?? [];

            if (empty($interventions)) {
                return;
            }

            // Store in var/cache so the frontend controller can serve them fast
            if (!is_dir(self::CACHE_DIR)) {
                mkdir(self::CACHE_DIR, 0755, true);
            }

            // Group by session_id for fast lookup
            $bySession = [];
            foreach ($interventions as $intervention) {
                $sessionId = $intervention['session_id'] ?? 'global';
                $bySession[$sessionId][] = $intervention;
            }

            foreach ($bySession as $sessionId => $items) {
                $file = self::CACHE_DIR . 'interventions_' . md5($sessionId) . '.json';
                // Merge with existing if present
                $existing = [];
                if (file_exists($file)) {
                    $existing = json_decode(file_get_contents($file), true) ?: [];
                }
                $merged = array_merge($existing, $items);
                file_put_contents($file, json_encode($merged));
            }

            $this->logger->info('Ecom360: Fetched interventions', [
                'count'    => count($interventions),
                'sessions' => count($bySession),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360: FetchInterventions error: ' . $e->getMessage());
        }
    }
}
