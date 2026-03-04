<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PopupShowOn implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'all_pages',    'label' => __('All Pages')],
            ['value' => 'homepage',     'label' => __('Homepage Only')],
            ['value' => 'product',      'label' => __('Product Pages Only')],
            ['value' => 'category',     'label' => __('Category Pages Only')],
            ['value' => 'cart',         'label' => __('Cart Page Only')],
            ['value' => 'checkout',     'label' => __('Checkout Page Only')],
        ];
    }
}
