<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ChatbotPosition implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'bottom-right', 'label' => __('Bottom Right')],
            ['value' => 'bottom-left',  'label' => __('Bottom Left')],
            ['value' => 'top-right',    'label' => __('Top Right')],
            ['value' => 'top-left',     'label' => __('Top Left')],
        ];
    }
}
