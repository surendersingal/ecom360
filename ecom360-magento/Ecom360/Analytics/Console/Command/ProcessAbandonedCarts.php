<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Console\Command;

use Ecom360\Analytics\Cron\AbandonedCartCron;
use Ecom360\Analytics\Helper\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: bin/magento ecom360:abandoned-carts
 *
 * Manually triggers abandoned cart processing (detection, recovery emails, sync).
 */
class ProcessAbandonedCarts extends Command
{
    private Config $config;
    private AbandonedCartCron $abandonedCartCron;

    public function __construct(Config $config, AbandonedCartCron $abandonedCartCron)
    {
        parent::__construct();
        $this->config = $config;
        $this->abandonedCartCron = $abandonedCartCron;
    }

    protected function configure(): void
    {
        $this->setName('ecom360:abandoned-carts')
            ->setDescription('Process abandoned carts — detect, send recovery emails, and sync to Ecom360');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>Ecom360 Analytics module is disabled.</error>');
            return self::FAILURE;
        }

        if (!$this->config->isAbandonedCartEnabled()) {
            $output->writeln('<error>Abandoned cart feature is disabled in configuration.</error>');
            return self::FAILURE;
        }

        $output->writeln('<info>Processing abandoned carts...</info>');

        try {
            $this->abandonedCartCron->execute();
            $output->writeln('<info>✓ Abandoned cart processing complete.</info>');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>✗ Error: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
    }
}
