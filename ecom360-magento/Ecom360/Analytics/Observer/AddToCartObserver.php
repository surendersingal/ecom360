<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Catalog\Api\CategoryRepositoryInterface;
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
    private CategoryRepositoryInterface $categoryRepository;
    private CustomerSession $customerSession;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        EventQueuePublisher $queue,
        CategoryRepositoryInterface $categoryRepository,
        CustomerSession $customerSession,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->queue = $queue;
        $this->categoryRepository = $categoryRepository;
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
        try {
            return $this->categoryRepository->get($categoryIds[0])->getName();
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
