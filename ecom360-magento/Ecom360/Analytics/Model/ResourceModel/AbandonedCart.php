<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class AbandonedCart extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ecom360_abandoned_cart', 'id');
    }
}
