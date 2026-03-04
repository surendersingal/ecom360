<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\ResourceModel\AbandonedCart;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ecom360\Analytics\Model\AbandonedCart;
use Ecom360\Analytics\Model\ResourceModel\AbandonedCart as AbandonedCartResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct(): void
    {
        $this->_init(AbandonedCart::class, AbandonedCartResource::class);
    }
}
