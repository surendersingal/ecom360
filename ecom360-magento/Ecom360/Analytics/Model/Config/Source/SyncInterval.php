<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SyncInterval implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'every_5_min',  'label' => __('Every 5 Minutes')],
            ['value' => 'every_15_min', 'label' => __('Every 15 Minutes')],
            ['value' => 'every_30_min', 'label' => __('Every 30 Minutes')],
            ['value' => 'every_hour',   'label' => __('Every Hour')],
            ['value' => 'every_6_hours','label' => __('Every 6 Hours')],
            ['value' => 'every_12_hours','label' => __('Every 12 Hours')],
            ['value' => 'daily',        'label' => __('Daily')],
        ];
    }
}
