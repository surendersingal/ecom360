<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Block;

use Ecom360\Analytics\Helper\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * PopupWidget block: renders the lead capture popup overlay.
 */
class PopupWidget extends Template
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
        return $this->config->isEnabled() && $this->config->isPopupEnabled();
    }

    public function getPopupTitle(): string
    {
        return $this->config->getPopupTitle();
    }

    public function getPopupDescription(): string
    {
        return $this->config->getPopupDescription();
    }

    public function shouldCollectName(): bool
    {
        return $this->config->isPopupCollectName();
    }

    public function shouldCollectEmail(): bool
    {
        return $this->config->isPopupCollectEmail();
    }

    public function shouldCollectPhone(): bool
    {
        return $this->config->isPopupCollectPhone();
    }

    public function shouldCollectDob(): bool
    {
        return $this->config->isPopupCollectDob();
    }

    public function getPopupConfigJson(): string
    {
        return $this->json->serialize([
            'trigger'        => $this->config->getPopupTrigger(),
            'delay'          => (int) $this->config->getPopupDelay(),
            'scroll_percent' => (int) $this->config->getPopupScrollPercent(),
            'show_on'        => $this->config->getPopupShowOn(),
            'frequency'      => $this->config->getPopupFrequency(),
            'submit_url'     => $this->getUrl('ecom360/popup/submit'),
        ]);
    }
}
