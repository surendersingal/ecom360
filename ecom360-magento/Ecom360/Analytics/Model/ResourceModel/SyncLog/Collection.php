<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\ResourceModel\SyncLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ecom360\Analytics\Model\SyncLog;
use Ecom360\Analytics\Model\ResourceModel\SyncLog as SyncLogResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'log_id';

    protected function _construct(): void
    {
        $this->_init(SyncLog::class, SyncLogResource::class);
    }
}
