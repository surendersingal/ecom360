<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Console\Command;

use Ecom360\Analytics\Helper\ApiClient;
use Ecom360\Analytics\Helper\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: bin/magento ecom360:test-connection
 */
class TestConnection extends Command
{
    private Config $config;
    private ApiClient $apiClient;

    public function __construct(Config $config, ApiClient $apiClient)
    {
        parent::__construct();
        $this->config = $config;
        $this->apiClient = $apiClient;
    }

    protected function configure(): void
    {
        $this->setName('ecom360:test-connection')
            ->setDescription('Test the connection to the Ecom360 platform')
            ->addOption('store', 's', InputOption::VALUE_OPTIONAL, 'Store ID to test', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>Ecom360 Analytics module is disabled.</error>');
            return self::FAILURE;
        }

        $storeId = $input->getOption('store') !== null ? (int) $input->getOption('store') : null;
        $serverUrl = $this->config->getServerUrl($storeId);
        $output->writeln("<info>Testing connection to {$serverUrl}...</info>");

        $result = $this->apiClient->testConnection($storeId);

        if ($result['success']) {
            $output->writeln('<info>✓ ' . $result['message'] . '</info>');
            if (!empty($result['tenant'])) {
                $output->writeln('  Tenant: ' . $result['tenant']);
            }
            return self::SUCCESS;
        }

        $output->writeln('<error>✗ ' . ($result['message'] ?? 'Connection failed') . '</error>');
        return self::FAILURE;
    }
}
