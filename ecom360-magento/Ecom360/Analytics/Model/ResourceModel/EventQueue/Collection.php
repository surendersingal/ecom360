<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\ResourceModel\EventQueue;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ecom360\Analytics\Model\EventQueue as Model;
use Ecom360\Analytics\Model\ResourceModel\EventQueue as ResourceModel;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
