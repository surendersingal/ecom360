<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\AbandonedCart;
use Ecom360\Analytics\Model\AbandonedCartFactory;
use Ecom360\Analytics\Model\ResourceModel\AbandonedCart as AbandonedCartResource;
use Ecom360\Analytics\Model\ResourceModel\AbandonedCart\CollectionFactory as AbandonedCartCollectionFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Tracks cart save events for abandoned cart detection.
 */
class CartSaveObserver implements ObserverInterface
{
    private Config $config;
    private AbandonedCartFactory $abandonedCartFactory;
    private AbandonedCartResource $abandonedCartResource;
    private AbandonedCartCollectionFactory $collectionFactory;
    private Json $json;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        AbandonedCartFactory $abandonedCartFactory,
        AbandonedCartResource $abandonedCartResource,
        AbandonedCartCollectionFactory $collectionFactory,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->abandonedCartFactory = $abandonedCartFactory;
        $this->abandonedCartResource = $abandonedCartResource;
        $this->collectionFactory = $collectionFactory;
        $this->json = $json;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isAbandonedCartEnabled()) {
            return;
        }

        try {
            /** @var \Magento\Checkout\Model\Cart $cart */
            $cart = $observer->getEvent()->getData('cart');
            $quote = $cart->getQuote();

            if (!$quote || !$quote->getId()) {
                return;
            }

            // Build items snapshot
            $items = [];
            foreach ($quote->getAllVisibleItems() as $item) {
                $items[] = [
                    'product_id' => (string) $item->getProductId(),
                    'name'       => $item->getName(),
                    'sku'        => $item->getSku(),
                    'qty'        => (int) $item->getQty(),
                    'price'      => (float) $item->getPrice(),
                    'row_total'  => (float) $item->getRowTotal(),
                ];
            }

            // Find or create abandoned cart record
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('quote_id', $quote->getId());
            $record = $collection->getFirstItem();

            if (!$record->getId()) {
                $record = $this->abandonedCartFactory->create();
                $record->setData('quote_id', $quote->getId());
            }

            $record->setData('customer_id', $quote->getCustomerId());
            $record->setData('customer_email', $quote->getCustomerEmail());
            $record->setData('customer_name', $quote->getCustomerFirstname()
                ? $quote->getCustomerFirstname() . ' ' . $quote->getCustomerLastname()
                : null);
            $record->setData('store_id', $quote->getStoreId());
            $record->setData('grand_total', (float) $quote->getGrandTotal());
            $record->setData('items_count', count($items));
            $record->setData('items_json', $this->json->serialize($items));
            $record->setData('status', AbandonedCart::STATUS_ACTIVE);
            $record->setData('last_activity_at', date('Y-m-d H:i:s'));

            $this->abandonedCartResource->save($record);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 cart_save observer error: ' . $e->getMessage());
        }
    }
}
