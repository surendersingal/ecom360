<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires when a product is added to the cart.
 * ★ ASYNC — queues event in DB (<1ms), never makes HTTP calls.
 */
class AddToCartObserver implements ObserverInterface
{
    private Config $config;
    private EventQueuePublisher $queue;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private CustomerSession $customerSession;
    private LoggerInterface $logger;

    /** @var array<int,string> In-process category name cache to avoid repeated DB lookups */
    private static array $categoryNameCache = [];

    public function __construct(
        Config $config,
        EventQueuePublisher $queue,
        CategoryCollectionFactory $categoryCollectionFactory,
        CustomerSession $customerSession,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->queue = $queue;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->customerSession = $customerSession;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isTrackCart()) {
            return;
        }

        try {
            /** @var \Magento\Quote\Model\Quote\Item $quoteItem */
            $quoteItem = $observer->getEvent()->getData('quote_item');
            $product = $quoteItem->getProduct();

            $this->queue->publishEvent('add_to_cart', [
                'product_id'   => (string) $product->getId(),
                'product_name' => $product->getName(),
                'sku'          => $product->getSku(),
                'price'        => (float) $product->getFinalPrice(),
                'quantity'     => (int) $quoteItem->getQty(),
                'category'     => $this->getProductCategory($product),
            ], $this->getCustomerIdentifier());
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 add_to_cart observer error: ' . $e->getMessage());
        }
    }

    private function getProductCategory($product): string
    {
        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            return 'Uncategorized';
        }

        $firstId = (int) $categoryIds[0];

        // Return from in-process cache if already loaded
        if (isset(self::$categoryNameCache[$firstId])) {
            return self::$categoryNameCache[$firstId];
        }

        try {
            // Load only uncached IDs via collection (single query, no N+1)
            $uncached = array_filter(
                array_map('intval', $categoryIds),
                fn($id) => !isset(self::$categoryNameCache[$id])
            );

            if (!empty($uncached)) {
                $collection = $this->categoryCollectionFactory->create();
                $collection->addAttributeToSelect('name')
                    ->addFieldToFilter('entity_id', ['in' => $uncached]);

                foreach ($collection as $cat) {
                    self::$categoryNameCache[(int) $cat->getId()] = (string) $cat->getName();
                }
            }

            return self::$categoryNameCache[$firstId] ?? 'Uncategorized';
        } catch (\Exception $e) {
            return 'Uncategorized';
        }
    }

    private function getCustomerIdentifier(): ?array
    {
        if ($this->customerSession->isLoggedIn()) {
            return ['type' => 'email', 'value' => $this->customerSession->getCustomer()->getEmail()];
        }
        return null;
    }
}
