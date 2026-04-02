<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Block;

use Ecom360\Analytics\Helper\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\App\RequestInterface;

/**
 * AI Search Results Page block — full-page search results with filters.
 */
class SearchResults extends Template
{
    private Config $config;
    private Json $json;
    private RequestInterface $request;

    public function __construct(
        Context $context,
        Config $config,
        Json $json,
        RequestInterface $request,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->json = $json;
        $this->request = $request;
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isAiSearchEnabled();
    }

    public function getSearchQuery(): string
    {
        return trim((string) $this->request->getParam('q', ''));
    }

    public function getApiEndpoint(): string
    {
        return $this->config->getServerUrl() . '/api/v1/search';
    }

    public function getApiKey(): string
    {
        return $this->config->getApiKey();
    }

    public function getSearchResultsUrl(): string
    {
        return $this->getUrl('ecom360/search/results');
    }

    public function getConfigJson(): string
    {
        return $this->json->serialize([
            'endpoint'    => $this->getApiEndpoint(),
            'apiKey'      => $this->getApiKey(),
            'query'       => $this->getSearchQuery(),
            'resultsUrl'  => $this->getSearchResultsUrl(),
        ]);
    }
}
