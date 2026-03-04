<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\ResourceModel\PushSubscription;

use Ecom360\Analytics\Model\PushSubscription;
use Ecom360\Analytics\Model\ResourceModel\PushSubscription as PushSubscriptionResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection for ecom360_push_subscription table.
 */
class Collection extends AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct(): void
    {
        $this->_init(PushSubscription::class, PushSubscriptionResource::class);
    }
}
