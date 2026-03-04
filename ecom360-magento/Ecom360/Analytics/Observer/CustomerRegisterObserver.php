<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * ★ ASYNC — queues event in DB (<1ms), never makes HTTP calls.
 */
class CustomerRegisterObserver implements ObserverInterface
{
    private Config $config;
    private EventQueuePublisher $queue;
    private LoggerInterface $logger;

    public function __construct(Config $config, EventQueuePublisher $queue, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->queue = $queue;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isTrackCustomerRegister()) {
            return;
        }

        try {
            /** @var \Magento\Customer\Model\Customer $customer */
            $customer = $observer->getEvent()->getData('customer');
            if (!$customer) {
                return;
            }

            $this->queue->publishEvent('register', [
                'source' => 'magento2',
            ], [
                'type'  => 'email',
                'value' => $customer->getEmail(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 customer_register observer error: ' . $e->getMessage());
        }
    }
}
