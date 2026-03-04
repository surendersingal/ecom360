<?php

declare(strict_types=1);

namespace Modules\DataSync\Enums;

/**
 * Supported e-commerce platforms.
 */
enum Platform: string
{
    case Magento2     = 'magento2';
    case WooCommerce  = 'woocommerce';
    case Shopify      = 'shopify';   // future
    case Custom       = 'custom';

    /** Human-readable label. */
    public function label(): string
    {
        return match ($this) {
            self::Magento2    => 'Magento 2',
            self::WooCommerce => 'WooCommerce',
            self::Shopify     => 'Shopify',
            self::Custom      => 'Custom',
        };
    }
}
