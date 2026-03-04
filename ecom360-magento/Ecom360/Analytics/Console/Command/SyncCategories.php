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
 * CLI: bin/magento ecom360:sync:categories
 */
class SyncCategories extends Command
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
        $this->setName('ecom360:sync:categories')
            ->setDescription('Sync all categories to the Ecom360 platform')
            ->addOption('store', 's', InputOption::VALUE_OPTIONAL, 'Store ID to sync', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>Ecom360 Analytics module is disabled.</error>');
            return self::FAILURE;
        }

        $storeId = $input->getOption('store') !== null ? (int) $input->getOption('store') : null;
        $output->writeln('<info>Starting category sync...</info>');

        $result = $this->dataSync->syncCategories($storeId, function (string $message) use ($output) {
            $output->writeln("  → {$message}");
        });

        $synced = $result['synced'] ?? 0;
        $failed = $result['failed'] ?? 0;

        $output->writeln(sprintf(
            '<info>✓ Category sync complete — %d synced, %d failed.</info>',
            $synced,
            $failed
        ));

        return $failed > 0 && $synced === 0 ? self::FAILURE : self::SUCCESS;
    }
}
