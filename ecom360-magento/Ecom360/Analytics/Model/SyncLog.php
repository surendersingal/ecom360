<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model;

use Magento\Framework\Model\AbstractModel;

class SyncLog extends AbstractModel
{
    public const ENTITY_TYPE_PRODUCT  = 'product';
    public const ENTITY_TYPE_CATEGORY = 'category';
    public const ENTITY_TYPE_ORDER    = 'order';
    public const ENTITY_TYPE_CUSTOMER = 'customer';
    public const ENTITY_TYPE_SALES    = 'sales';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    protected function _construct(): void
    {
        $this->_init(\Ecom360\Analytics\Model\ResourceModel\SyncLog::class);
    }
}
