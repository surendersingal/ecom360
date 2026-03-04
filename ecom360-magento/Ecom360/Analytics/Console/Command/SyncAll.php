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
 * CLI: bin/magento ecom360:sync:all
 */
class SyncAll extends Command
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
        $this->setName('ecom360:sync:all')
            ->setDescription('Sync all entities (products, categories, orders, customers) to Ecom360')
            ->addOption('store', 's', InputOption::VALUE_OPTIONAL, 'Store ID to sync', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>Ecom360 Analytics module is disabled.</error>');
            return self::FAILURE;
        }

        $storeId = $input->getOption('store') !== null ? (int) $input->getOption('store') : null;
        $output->writeln('<info>Starting full sync...</info>');

        $results = $this->dataSync->syncAll($storeId, function (string $message) use ($output) {
            $output->writeln("  → {$message}");
        });

        $output->writeln('<info>✓ Full sync complete.</info>');
        $allFailed = true;
        foreach ($results as $entity => $entityResult) {
            $synced = $entityResult['synced'] ?? 0;
            $failed = $entityResult['failed'] ?? 0;
            $status = $failed === 0 ? '✓' : ($synced > 0 ? '⚠' : '✗');
            if ($synced > 0 || $failed === 0) {
                $allFailed = false;
            }
            $output->writeln("  {$status} {$entity}: {$synced} synced, {$failed} failed");
        }

        return $allFailed ? self::FAILURE : self::SUCCESS;
    }
}
