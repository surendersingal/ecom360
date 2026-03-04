<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Event queue model — buffers tracking events / sync payloads for async processing.
 *
 * Observers write here (<1ms DB insert) instead of making blocking HTTP calls.
 * A cron job flushes the queue every minute.
 */
class EventQueue extends AbstractModel
{
    public const TYPE_EVENT = 'event';    // → sendEvent() / postPublic()
    public const TYPE_SYNC  = 'sync';     // → syncData() / server-to-server

    public const STATUS_PENDING  = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE     = 'done';
    public const STATUS_FAILED   = 'failed';

    protected function _construct(): void
    {
        $this->_init(\Ecom360\Analytics\Model\ResourceModel\EventQueue::class);
    }
}
