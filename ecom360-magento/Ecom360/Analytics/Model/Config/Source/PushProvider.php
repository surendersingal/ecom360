<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PushProvider implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'firebase',  'label' => __('Firebase Cloud Messaging (FCM)')],
            ['value' => 'onesignal', 'label' => __('OneSignal')],
        ];
    }
}
