<?php

declare(strict_types=1);

namespace Modules\DataSync\Services\Normalizers;

use Modules\DataSync\Enums\Platform;

/**
 * Normalizes product data from different e-commerce platforms
 * into a unified format for storage in MongoDB.
 */
final class ProductNormalizer
{
    /**
     * @param  array    $raw       Raw product data from the platform
     * @param  Platform $platform  Source platform
     * @return array               Normalized product document
     */
    public function normalize(array $raw, Platform $platform): array
    {
        return match ($platform) {
            Platform::Magento2    => $this->fromMagento($raw),
            Platform::WooCommerce => $this->fromWooCommerce($raw),
            default               => $this->fromGeneric($raw),
        };
    }

    /**
     * Normalize a batch of products.
     *
     * @param  list<array> $products
     * @param  Platform    $platform
     * @return list<array>
     */
    public function normalizeBatch(array $products, Platform $platform): array
    {
        return array_map(fn (array $p) => $this->normalize($p, $platform), $products);
    }

    private function fromMagento(array $p): array
    {
        return [
            'external_id'       => (string) ($p['id'] ?? $p['entity_id'] ?? ''),
            'sku'               => (string) ($p['sku'] ?? ''),
            'name'              => (string) ($p['name'] ?? ''),
            'price'             => (float) ($p['price'] ?? 0),
            'special_price'     => isset($p['special_price']) ? (float) $p['special_price'] : null,
            'status'            => (string) ($p['status'] ?? 'enabled'),
            'type'              => (string) ($p['type'] ?? 'simple'),
            'visibility'        => $p['visibility'] ?? null,
            'weight'            => $p['weight'] ?? null,
            'url_key'           => $p['url_key'] ?? null,
            'image_url'         => $p['image_url'] ?? null,
            'description'       => $p['description'] ?? null,
            'short_description' => $p['short_description'] ?? null,
            'categories'        => $p['categories'] ?? [],
            'category_ids'      => array_map('strval', $p['category_ids'] ?? []),
            'variants'          => $p['variants'] ?? [],
            'attributes'        => $p['attributes'] ?? [],
        ];
    }

    private function fromWooCommerce(array $p): array
    {
        return [
            'external_id'       => (string) ($p['id'] ?? $p['product_id'] ?? ''),
            'sku'               => (string) ($p['sku'] ?? ''),
            'name'              => (string) ($p['name'] ?? $p['title'] ?? ''),
            'price'             => (float) ($p['price'] ?? $p['regular_price'] ?? 0),
            'special_price'     => isset($p['sale_price']) && $p['sale_price'] !== '' ? (float) $p['sale_price'] : null,
            'status'            => (string) ($p['status'] ?? 'publish'),
            'type'              => (string) ($p['type'] ?? 'simple'),
            'visibility'        => $p['catalog_visibility'] ?? null,
            'weight'            => $p['weight'] ?? null,
            'url_key'           => $p['slug'] ?? null,
            'image_url'         => $p['image'] ?? ($p['images'][0]['src'] ?? null),
            'description'       => $p['description'] ?? null,
            'short_description' => $p['short_description'] ?? null,
            'categories'        => array_column($p['categories'] ?? [], 'name'),
            'category_ids'      => array_map('strval', array_column($p['categories'] ?? [], 'id')),
            'variants'          => $this->normalizeWooVariations($p['variations'] ?? []),
            'attributes'        => $p['attributes'] ?? [],
        ];
    }

    private function fromGeneric(array $p): array
    {
        return [
            'external_id'       => (string) ($p['id'] ?? $p['external_id'] ?? ''),
            'sku'               => (string) ($p['sku'] ?? ''),
            'name'              => (string) ($p['name'] ?? ''),
            'price'             => (float) ($p['price'] ?? 0),
            'special_price'     => isset($p['special_price']) ? (float) $p['special_price'] : null,
            'status'            => (string) ($p['status'] ?? 'active'),
            'type'              => (string) ($p['type'] ?? 'simple'),
            'visibility'        => $p['visibility'] ?? null,
            'weight'            => $p['weight'] ?? null,
            'url_key'           => $p['url_key'] ?? $p['slug'] ?? null,
            'image_url'         => $p['image_url'] ?? $p['image'] ?? null,
            'description'       => $p['description'] ?? null,
            'short_description' => $p['short_description'] ?? null,
            'categories'        => $p['categories'] ?? [],
            'category_ids'      => $p['category_ids'] ?? [],
            'variants'          => $p['variants'] ?? [],
            'attributes'        => $p['attributes'] ?? [],
        ];
    }

    private function normalizeWooVariations(array $variations): array
    {
        return array_map(fn (array $v) => [
            'id'            => (string) ($v['id'] ?? ''),
            'sku'           => $v['sku'] ?? '',
            'price'         => (float) ($v['price'] ?? 0),
            'regular_price' => (float) ($v['regular_price'] ?? 0),
            'sale_price'    => isset($v['sale_price']) ? (float) $v['sale_price'] : null,
            'stock_status'  => $v['stock_status'] ?? 'instock',
            'attributes'    => $v['attributes'] ?? [],
        ], $variations);
    }
}
