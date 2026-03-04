<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Block;

use Ecom360\Analytics\Helper\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * PushNotification block: renders push notification opt-in prompt + service worker registration.
 */
class PushNotification extends Template
{
    private Config $config;
    private Json $json;

    public function __construct(
        Context $context,
        Config $config,
        Json $json,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->json = $json;
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isPushEnabled();
    }

    public function getPushConfigJson(): string
    {
        $provider = $this->config->getPushProvider();

        $config = [
            'provider'     => $provider,
            'prompt_delay' => (int) $this->config->getPushPromptDelay(),
            'subscribe_url'=> $this->getUrl('rest/V1/ecom360/push/subscribe'),
        ];

        if ($provider === 'firebase') {
            $config['firebase'] = [
                'api_key'    => $this->config->getFirebaseApiKey(),
                'sender_id'  => $this->config->getFirebaseSenderId(),
                'sw_path'    => $this->getViewFileUrl('Ecom360_Analytics::js/ecom360-firebase-sw.js'),
            ];
        } elseif ($provider === 'onesignal') {
            $config['onesignal'] = [
                'app_id' => $this->config->getOneSignalAppId(),
            ];
        }

        return $this->json->serialize($config);
    }
}
