<?php

declare(strict_types=1);

namespace Modules\DataSync\Services\Normalizers;

use Modules\DataSync\Enums\Platform;

/**
 * Normalizes inventory / stock data from different e-commerce platforms.
 */
final class InventoryNormalizer
{
    public function normalize(array $raw, Platform $platform): array
    {
        return match ($platform) {
            Platform::Magento2    => $this->fromMagento($raw),
            Platform::WooCommerce => $this->fromWooCommerce($raw),
            default               => $this->fromGeneric($raw),
        };
    }

    public function normalizeBatch(array $items, Platform $platform): array
    {
        return array_map(fn (array $i) => $this->normalize($i, $platform), $items);
    }

    private function fromMagento(array $i): array
    {
        return [
            'product_id'    => (string) ($i['product_id'] ?? ''),
            'sku'           => (string) ($i['sku'] ?? ''),
            'name'          => $i['name'] ?? null,
            'price'         => (float) ($i['price'] ?? 0),
            'cost'          => isset($i['cost']) ? (float) $i['cost'] : null,
            'special_price' => isset($i['special_price']) ? (float) $i['special_price'] : null,
            'qty'           => (float) ($i['qty'] ?? 0),
            'is_in_stock'   => (bool) ($i['is_in_stock'] ?? true),
            'min_qty'       => (float) ($i['min_qty'] ?? 0),
            'low_stock'     => (bool) ($i['low_stock'] ?? false),
        ];
    }

    private function fromWooCommerce(array $i): array
    {
        return [
            'product_id'    => (string) ($i['id'] ?? $i['product_id'] ?? ''),
            'sku'           => (string) ($i['sku'] ?? ''),
            'name'          => $i['name'] ?? null,
            'price'         => (float) ($i['price'] ?? $i['regular_price'] ?? 0),
            'cost'          => isset($i['cost']) ? (float) $i['cost'] : null,
            'special_price' => isset($i['sale_price']) && $i['sale_price'] !== '' ? (float) $i['sale_price'] : null,
            'qty'           => (float) ($i['stock_quantity'] ?? $i['qty'] ?? 0),
            'is_in_stock'   => ($i['stock_status'] ?? 'instock') === 'instock',
            'min_qty'       => (float) ($i['low_stock_amount'] ?? 0),
            'low_stock'     => (bool) ($i['low_stock'] ?? false),
        ];
    }

    private function fromGeneric(array $i): array
    {
        return [
            'product_id'    => (string) ($i['product_id'] ?? $i['id'] ?? ''),
            'sku'           => (string) ($i['sku'] ?? ''),
            'name'          => $i['name'] ?? null,
            'price'         => (float) ($i['price'] ?? 0),
            'cost'          => $i['cost'] ?? null,
            'special_price' => $i['special_price'] ?? null,
            'qty'           => (float) ($i['qty'] ?? $i['stock_quantity'] ?? 0),
            'is_in_stock'   => (bool) ($i['is_in_stock'] ?? true),
            'min_qty'       => (float) ($i['min_qty'] ?? 0),
            'low_stock'     => (bool) ($i['low_stock'] ?? false),
        ];
    }
}
