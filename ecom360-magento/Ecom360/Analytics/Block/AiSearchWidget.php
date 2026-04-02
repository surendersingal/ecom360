<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Block;

use Ecom360\Analytics\Helper\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * AI Search widget block — enhances default search with
 * AI-powered suggestions, facets, and visual search.
 */
class AiSearchWidget extends Template
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
        return $this->config->isEnabled() && $this->config->isAiSearchEnabled();
    }

    public function isVisualSearchEnabled(): bool
    {
        return $this->config->isAiSearchVisualEnabled();
    }

    public function isVoiceSearchEnabled(): bool
    {
        return $this->config->isAiSearchVoiceEnabled();
    }

    public function getApiEndpoint(): string
    {
        return $this->config->getServerUrl() . '/api/v1/search';
    }

    public function getApiKey(): string
    {
        return $this->config->getApiKey();
    }

    public function getConfigJson(): string
    {
        return $this->json->serialize([
            'endpoint'     => $this->getApiEndpoint(),
            'apiKey'       => $this->getApiKey(),
            'visualSearch' => $this->isVisualSearchEnabled(),
            'voiceSearch'  => $this->isVoiceSearchEnabled(),
        ]);
    }
}
