<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Cron;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\DataSync;
use Psr\Log\LoggerInterface;

class SyncOrdersCron
{
    private Config $config;
    private DataSync $dataSync;
    private LoggerInterface $logger;

    public function __construct(Config $config, DataSync $dataSync, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->dataSync = $dataSync;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // Sync orders from last 24 hours for incremental sync
        $fromDate = date('Y-m-d H:i:s', strtotime('-24 hours'));

        $this->logger->info('Ecom360: Starting order sync cron');
        $result = $this->dataSync->syncOrders(null, $fromDate);
        $this->logger->info('Ecom360: Order sync completed', $result);
    }
}
