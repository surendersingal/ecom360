<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PopupTrigger implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'time_delay',   'label' => __('Time Delay')],
            ['value' => 'scroll',       'label' => __('Scroll Percentage')],
            ['value' => 'exit_intent',  'label' => __('Exit Intent')],
            ['value' => 'page_count',   'label' => __('After N Page Views')],
        ];
    }
}
