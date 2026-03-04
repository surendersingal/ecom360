<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Controller\Popup;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\PopupCapture;
use Ecom360\Analytics\Model\ResourceModel\PopupCapture as PopupCaptureResource;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Frontend AJAX: save popup capture form submission.
 * URL: POST /ecom360/popup/submit
 */
class Submit implements HttpPostActionInterface
{
    private RequestInterface $request;
    private JsonFactory $jsonFactory;
    private Config $config;
    private PopupCaptureResource $popupCaptureResource;
    private PopupCapture $popupCaptureModel;
    private CustomerSession $customerSession;
    private LoggerInterface $logger;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        PopupCaptureResource $popupCaptureResource,
        PopupCapture $popupCaptureModel,
        CustomerSession $customerSession,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->config = $config;
        $this->popupCaptureResource = $popupCaptureResource;
        $this->popupCaptureModel = $popupCaptureModel;
        $this->customerSession = $customerSession;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isEnabled() || !$this->config->isPopupEnabled()) {
            return $result->setData(['success' => false, 'message' => 'Popup is disabled.']);
        }

        try {
            $body = $this->request->getContent();
            $data = json_decode($body, true) ?: [];

            // Validate at least email is present
            if (empty($data['email'])) {
                return $result->setData(['success' => false, 'message' => 'Email is required.']);
            }

            $capture = clone $this->popupCaptureModel;
            $capture->setData([
                'session_id'         => $data['session_id'] ?? null,
                'customer_id'        => $this->customerSession->isLoggedIn()
                    ? (int) $this->customerSession->getCustomerId()
                    : null,
                'name'               => $data['name'] ?? null,
                'email'              => $data['email'],
                'phone'              => $data['phone'] ?? null,
                'dob'                => $data['dob'] ?? null,
                'extra_data'         => json_encode(array_diff_key($data, array_flip([
                    'name', 'email', 'phone', 'dob', 'session_id', 'page_url'
                ]))),
                'page_url'           => $data['page_url'] ?? null,
                'synced_to_ecom360'  => 0,
            ]);
            $this->popupCaptureResource->save($capture);

            return $result->setData(['success' => true, 'message' => 'Thank you!']);

        } catch (\Exception $e) {
            $this->logger->error('[Ecom360] Popup submit error: ' . $e->getMessage());
            return $result->setData(['success' => false, 'message' => 'Server error.']);
        }
    }
}
