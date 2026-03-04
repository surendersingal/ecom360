<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Model for the ecom360_push_subscription table.
 */
class PushSubscription extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(\Ecom360\Analytics\Model\ResourceModel\PushSubscription::class);
    }
}
