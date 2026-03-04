<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Controller\Cart;

use Ecom360\Analytics\Model\AbandonedCart;
use Ecom360\Analytics\Model\ResourceModel\AbandonedCart as AbandonedCartResource;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\SalesRule\Model\CouponFactory;
use Psr\Log\LoggerInterface;

/**
 * Cart recovery controller: restores abandoned cart via token link.
 * URL: /ecom360/cart/recover?token=xxxxx
 */
class Recover implements HttpGetActionInterface
{
    private RequestInterface $request;
    private RedirectFactory $redirectFactory;
    private AbandonedCartResource $abandonedCartResource;
    private AbandonedCart $abandonedCartModel;
    private CartRepositoryInterface $cartRepository;
    private CheckoutSession $checkoutSession;
    private CouponFactory $couponFactory;
    private ManagerInterface $messageManager;
    private LoggerInterface $logger;

    public function __construct(
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        AbandonedCartResource $abandonedCartResource,
        AbandonedCart $abandonedCartModel,
        CartRepositoryInterface $cartRepository,
        CheckoutSession $checkoutSession,
        CouponFactory $couponFactory,
        ManagerInterface $messageManager,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->abandonedCartResource = $abandonedCartResource;
        $this->abandonedCartModel = $abandonedCartModel;
        $this->cartRepository = $cartRepository;
        $this->checkoutSession = $checkoutSession;
        $this->couponFactory = $couponFactory;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();
        $token = $this->request->getParam('token');

        if (!$token) {
            $this->messageManager->addErrorMessage(__('Invalid recovery link.'));
            return $redirect->setPath('/');
        }

        try {
            // Load abandoned cart by recovery_token
            $this->abandonedCartResource->load($this->abandonedCartModel, $token, 'recovery_token');

            if (!$this->abandonedCartModel->getId()) {
                $this->messageManager->addErrorMessage(__('This recovery link has expired or is invalid.'));
                return $redirect->setPath('/');
            }

            $quoteId = $this->abandonedCartModel->getData('quote_id');

            // Load the original quote
            try {
                $quote = $this->cartRepository->get($quoteId);
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(__('Your cart could not be restored. It may have been cleared.'));
                return $redirect->setPath('/');
            }

            if (!$quote->getIsActive()) {
                $quote->setIsActive(true);
                $this->cartRepository->save($quote);
            }

            // Apply coupon if available
            $couponCode = $this->abandonedCartModel->getData('coupon_code');
            if ($couponCode) {
                $quote->setCouponCode($couponCode);
                $this->cartRepository->save($quote);
                $this->messageManager->addSuccessMessage(
                    __('A special discount has been applied to your cart! Coupon: %1', $couponCode)
                );
            }

            // Set the quote in the checkout session
            $this->checkoutSession->setQuoteId($quote->getId());

            // Mark as recovered
            $this->abandonedCartModel->setData('status', 'recovered');
            $this->abandonedCartResource->save($this->abandonedCartModel);

            $this->messageManager->addSuccessMessage(__('Your cart has been restored!'));
            return $redirect->setPath('checkout/cart');

        } catch (\Exception $e) {
            $this->logger->error('[Ecom360] Cart recovery error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Something went wrong restoring your cart.'));
            return $redirect->setPath('/');
        }
    }
}
