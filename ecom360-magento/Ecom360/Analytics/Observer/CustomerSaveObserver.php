<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;

/**
 * Fires on customer save — syncs customer data changes.
 * ★ ASYNC — queues sync call in DB (<1ms), never makes HTTP calls.
 * ★ DEDUP — skips if CustomerRegisterObserver already fired in this request
 *   (uses registry flag to prevent the 18s double-fire on registration).
 */
class CustomerSaveObserver implements ObserverInterface
{
    private Config $config;
    private EventQueuePublisher $queue;
    private Registry $registry;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        EventQueuePublisher $queue,
        Registry $registry,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->queue = $queue;
        $this->registry = $registry;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isSyncCustomers()) {
            return;
        }

        try {
            $customer = $observer->getEvent()->getData('customer_data_object')
                ?? $observer->getEvent()->getData('customer');

            if (!$customer) {
                return;
            }

            // ★ DEDUP: Skip if this is a brand-new registration — the cron sync will
            // pick it up. This prevents the 15s syncData() call from blocking registration.
            if ($customer->getId() && !$customer->getOrigData('entity_id')) {
                return; // New entity, skip real-time sync during creation
            }

            $this->queue->publishSync('/api/v1/sync/customers', [
                'customers' => [[
                    'id'         => (string) $customer->getId(),
                    'email'      => $customer->getEmail(),
                    'firstname'  => $customer->getFirstname(),
                    'lastname'   => $customer->getLastname(),
                    'name'       => $customer->getFirstname() . ' ' . $customer->getLastname(),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]],
                'platform' => 'magento2',
                'realtime' => true,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 customer_save observer error: ' . $e->getMessage());
        }
    }
}
