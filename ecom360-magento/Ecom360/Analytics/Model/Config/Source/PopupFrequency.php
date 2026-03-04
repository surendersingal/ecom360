<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PopupFrequency implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'every_visit',       'label' => __('Every Visit')],
            ['value' => 'once_per_session',  'label' => __('Once Per Session')],
            ['value' => 'once_per_day',      'label' => __('Once Per Day')],
            ['value' => 'once_per_week',     'label' => __('Once Per Week')],
            ['value' => 'once_ever',         'label' => __('Once Ever (until cookies cleared)')],
        ];
    }
}
