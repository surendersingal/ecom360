<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Cron;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\DataSync;
use Psr\Log\LoggerInterface;

class SyncSalesCron
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

        $fromDate = date('Y-m-d', strtotime('-30 days'));

        $this->logger->info('Ecom360: Starting sales data sync cron');
        $result = $this->dataSync->syncSalesData(null, $fromDate);
        $this->logger->info('Ecom360: Sales data sync completed', $result);
    }
}
