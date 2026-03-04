<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\ResourceModel\PopupCapture;

use Ecom360\Analytics\Model\PopupCapture;
use Ecom360\Analytics\Model\ResourceModel\PopupCapture as PopupCaptureResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection for ecom360_popup_capture table.
 */
class Collection extends AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct(): void
    {
        $this->_init(PopupCapture::class, PopupCaptureResource::class);
    }
}
