<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Controller\Adminhtml\System;

use Ecom360\Analytics\Helper\ApiClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Admin AJAX controller: test connection to ecom360 server.
 */
class TestConnection extends Action
{
    public const ADMIN_RESOURCE = 'Ecom360_Analytics::config';

    private ApiClient $apiClient;
    private JsonFactory $jsonFactory;

    public function __construct(
        Context $context,
        ApiClient $apiClient,
        JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
        $this->apiClient = $apiClient;
        $this->jsonFactory = $jsonFactory;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $response = $this->apiClient->testConnection();
            if ($response['success'] ?? false) {
                return $result->setData([
                    'success' => true,
                    'message' => __('Connection successful! Server responded correctly.'),
                ]);
            }

            return $result->setData([
                'success' => false,
                'message' => __('Connection failed: %1', $response['message'] ?? 'Unknown error'),
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => __('Connection error: %1', $e->getMessage()),
            ]);
        }
    }
}
