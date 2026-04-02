<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Lists all product attributes as options for the "Brand Attribute" config dropdown.
 * Provides a sensible default of "manufacturer".
 */
class ProductAttribute implements OptionSourceInterface
{
    private CollectionFactory $attributeCollectionFactory;

    public function __construct(CollectionFactory $attributeCollectionFactory)
    {
        $this->attributeCollectionFactory = $attributeCollectionFactory;
    }

    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- None (do not sync brand) --')],
        ];

        $collection = $this->attributeCollectionFactory->create();
        $collection->addVisibleFilter()
            ->setOrder('frontend_label', 'ASC');

        foreach ($collection as $attribute) {
            $label = $attribute->getFrontendLabel();
            $code  = $attribute->getAttributeCode();

            if (!$label || !$code) {
                continue;
            }

            // Mark common brand-related attributes for easy identification
            $suffix = '';
            if (in_array($code, ['manufacturer', 'brand'], true)) {
                $suffix = ' ★';
            }

            $options[] = [
                'value' => $code,
                'label' => sprintf('%s (%s)%s', $label, $code, $suffix),
            ];
        }

        return $options;
    }
}
