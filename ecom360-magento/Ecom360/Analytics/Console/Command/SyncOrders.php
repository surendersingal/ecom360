<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Console\Command;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\DataSync;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: bin/magento ecom360:sync:orders
 */
class SyncOrders extends Command
{
    private Config $config;
    private DataSync $dataSync;

    public function __construct(Config $config, DataSync $dataSync)
    {
        parent::__construct();
        $this->config = $config;
        $this->dataSync = $dataSync;
    }

    protected function configure(): void
    {
        $this->setName('ecom360:sync:orders')
            ->setDescription('Sync all orders to the Ecom360 platform')
            ->addOption('store', 's', InputOption::VALUE_OPTIONAL, 'Store ID to sync', null)
            ->addOption('from', 'f', InputOption::VALUE_OPTIONAL, 'From date (YYYY-MM-DD)', null)
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Orders per API call (default: 10)', '10')
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Seconds to sleep between batches (default: 6)', '6')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max number of orders to sync (newest first)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>Ecom360 Analytics module is disabled.</error>');
            return self::FAILURE;
        }

        $storeId   = $input->getOption('store') !== null ? (int) $input->getOption('store') : null;
        $fromDate  = $input->getOption('from');
        $batchSize = (int) $input->getOption('batch-size');
        $delay     = (int) $input->getOption('delay');
        $limit     = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;

        $output->writeln(sprintf(
            '<info>Starting order sync (batch=%d, delay=%ds, limit=%s) …</info>',
            $batchSize,
            $delay,
            $limit ? (string)$limit : 'all'
        ));

        $result = $this->dataSync->syncOrders($storeId, $fromDate, function (string $message) use ($output) {
            $output->writeln("  → {$message}");
        }, $batchSize, $delay, $limit);

        $synced = $result['synced'] ?? 0;
        $failed = $result['failed'] ?? 0;

        $output->writeln(sprintf(
            '<info>✓ Order sync complete — %d synced, %d failed.</info>',
            $synced,
            $failed
        ));

        return $failed > 0 && $synced === 0 ? self::FAILURE : self::SUCCESS;
    }
}
