<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model;

use Magento\Framework\Model\AbstractModel;

class AbandonedCart extends AbstractModel
{
    public const STATUS_ACTIVE     = 'active';
    public const STATUS_ABANDONED  = 'abandoned';
    public const STATUS_RECOVERED  = 'recovered';
    public const STATUS_EMAIL_SENT = 'email_sent';
    public const STATUS_CONVERTED  = 'converted';

    protected function _construct(): void
    {
        $this->_init(\Ecom360\Analytics\Model\ResourceModel\AbandonedCart::class);
    }
}
