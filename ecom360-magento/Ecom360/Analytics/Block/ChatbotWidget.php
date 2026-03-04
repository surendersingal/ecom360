<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Block;

use Ecom360\Analytics\Helper\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Chatbot floating widget block.
 * Renders the chat bubble + iframe/AJAX-based chat window.
 */
class ChatbotWidget extends Template
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
        return $this->config->isEnabled() && $this->config->isChatbotEnabled();
    }

    public function getPosition(): string
    {
        return $this->config->getChatbotPosition();
    }

    public function getGreeting(): string
    {
        return $this->config->getChatbotGreeting();
    }

    public function getApiEndpoint(): string
    {
        return $this->config->getServerUrl() . '/api/v1/chatbot';
    }

    public function getApiKey(): string
    {
        return $this->config->getApiKey();
    }

    public function getConfigJson(): string
    {
        return $this->json->serialize([
            'endpoint' => $this->getApiEndpoint(),
            'apiKey'   => $this->getApiKey(),
            'position' => $this->getPosition(),
            'greeting' => $this->getGreeting(),
        ]);
    }
}
