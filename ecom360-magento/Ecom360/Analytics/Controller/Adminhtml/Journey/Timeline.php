<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Controller\Adminhtml\Journey;

use Ecom360\Analytics\Helper\ApiClient;
use Ecom360\Analytics\Helper\Config;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Admin AJAX controller: fetch customer journey timeline from ecom360 server.
 */
class Timeline extends Action
{
    public const ADMIN_RESOURCE = 'Ecom360_Analytics::journey';

    private ApiClient $apiClient;
    private Config $config;
    private JsonFactory $jsonFactory;
    private CurlFactory $curlFactory;
    private Json $json;

    public function __construct(
        Context $context,
        ApiClient $apiClient,
        Config $config,
        JsonFactory $jsonFactory,
        CurlFactory $curlFactory,
        Json $json
    ) {
        parent::__construct($context);
        $this->apiClient = $apiClient;
        $this->config = $config;
        $this->jsonFactory = $jsonFactory;
        $this->curlFactory = $curlFactory;
        $this->json = $json;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $customerId = $this->getRequest()->getParam('customer_id');
        $email = $this->getRequest()->getParam('email');

        if (!$customerId && !$email) {
            return $result->setData(['success' => false, 'message' => 'Customer ID or email required.']);
        }

        try {
            $serverUrl = rtrim($this->config->getServerUrl(), '/');
            $url = $serverUrl . '/api/v1/journey';
            $params = [];
            if ($email) {
                $params['email'] = $email;
            }
            if ($customerId) {
                $params['customer_id'] = $customerId;
            }

            $curl = $this->curlFactory->create();
            $curl->setTimeout(15);
            $curl->setHeaders([
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json',
                'X-Ecom360-Key'    => $this->config->getApiKey(),
                'X-Ecom360-Secret' => $this->config->getSecretKey(),
            ]);
            $curl->get($url . '?' . http_build_query($params));

            $httpCode = $curl->getStatus();

            if ($httpCode === 200) {
                $data = $this->json->unserialize($curl->getBody());
                return $result->setData(['success' => true, 'events' => $data['data'] ?? []]);
            }

            return $result->setData([
                'success' => false,
                'message' => 'Server returned HTTP ' . $httpCode,
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
