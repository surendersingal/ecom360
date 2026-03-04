<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Cron;

use Ecom360\Analytics\Helper\ApiClient;
use Ecom360\Analytics\Helper\Config;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Sync popup form captures to the Ecom360 platform.
 */
class SyncPopupCapturesCron
{
    private Config $config;
    private ApiClient $apiClient;
    private ResourceConnection $resource;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        ApiClient $apiClient,
        ResourceConnection $resource,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->resource = $resource;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isPopupEnabled()) {
            return;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('ecom360_popup_capture');

        $captures = $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->where('synced_to_ecom360 = ?', 0)
                ->limit(50)
        );

        if (empty($captures)) {
            return;
        }

        $batch = [];
        foreach ($captures as $capture) {
            $batch[] = [
                'session_id'   => $capture['session_id'],
                'customer_id'  => $capture['customer_id'],
                'name'         => $capture['name'],
                'email'        => $capture['email'],
                'phone'        => $capture['phone'],
                'dob'          => $capture['dob'],
                'extra_data'   => $capture['extra_data'],
                'page_url'     => $capture['page_url'],
                'captured_at'  => $capture['created_at'],
            ];
        }

        $result = $this->apiClient->syncData('/api/v1/sync/popup-captures', [
            'captures' => $batch,
            'platform' => 'magento2',
            'store_id' => 0,
        ]);

        if ($result['success']) {
            $ids = array_column($captures, 'id');
            $connection->update($table, ['synced_to_ecom360' => 1], ['id IN (?)' => $ids]);
            $this->logger->info(sprintf('Ecom360: Synced %d popup captures', count($batch)));
        }
    }
}
