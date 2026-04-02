<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Controller\Search;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Frontend: AI search results page.
 * URL: GET /ecom360/search/results?q=...
 */
class Results implements HttpGetActionInterface
{
    private PageFactory $pageFactory;

    public function __construct(PageFactory $pageFactory)
    {
        $this->pageFactory = $pageFactory;
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Search Results'));
        return $page;
    }
}
