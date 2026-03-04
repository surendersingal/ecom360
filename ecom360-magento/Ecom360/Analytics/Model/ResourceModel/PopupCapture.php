<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for the ecom360_popup_capture table.
 */
class PopupCapture extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ecom360_popup_capture', 'id');
    }
}
