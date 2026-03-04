<?php

declare(strict_types=1);

namespace Modules\DataSync\Services\Normalizers;

use Modules\DataSync\Enums\Platform;

/**
 * Normalizes category data from different e-commerce platforms.
 */
final class CategoryNormalizer
{
    public function normalize(array $raw, Platform $platform): array
    {
        return match ($platform) {
            Platform::Magento2    => $this->fromMagento($raw),
            Platform::WooCommerce => $this->fromWooCommerce($raw),
            default               => $this->fromGeneric($raw),
        };
    }

    public function normalizeBatch(array $categories, Platform $platform): array
    {
        return array_map(fn (array $c) => $this->normalize($c, $platform), $categories);
    }

    private function fromMagento(array $c): array
    {
        return [
            'external_id'     => (string) ($c['id'] ?? $c['entity_id'] ?? ''),
            'name'            => (string) ($c['name'] ?? ''),
            'url_key'         => $c['url_key'] ?? null,
            'is_active'       => (bool) ($c['is_active'] ?? true),
            'level'           => (int) ($c['level'] ?? 0),
            'position'        => (int) ($c['position'] ?? 0),
            'parent_id'       => isset($c['parent_id']) ? (string) $c['parent_id'] : null,
            'path'            => $c['path'] ?? null,
            'description'     => $c['description'] ?? null,
            'include_in_menu' => (bool) ($c['include_in_menu'] ?? true),
            'product_count'   => (int) ($c['product_count'] ?? 0),
        ];
    }

    private function fromWooCommerce(array $c): array
    {
        return [
            'external_id'     => (string) ($c['id'] ?? $c['term_id'] ?? ''),
            'name'            => (string) ($c['name'] ?? ''),
            'url_key'         => $c['slug'] ?? null,
            'is_active'       => true,
            'level'           => (int) ($c['level'] ?? 0),
            'position'        => (int) ($c['menu_order'] ?? 0),
            'parent_id'       => isset($c['parent']) && $c['parent'] > 0 ? (string) $c['parent'] : null,
            'path'            => null,
            'description'     => $c['description'] ?? null,
            'include_in_menu' => (bool) ($c['display'] ?? true),
            'product_count'   => (int) ($c['count'] ?? 0),
        ];
    }

    private function fromGeneric(array $c): array
    {
        return [
            'external_id'     => (string) ($c['id'] ?? $c['external_id'] ?? ''),
            'name'            => (string) ($c['name'] ?? ''),
            'url_key'         => $c['url_key'] ?? $c['slug'] ?? null,
            'is_active'       => (bool) ($c['is_active'] ?? true),
            'level'           => (int) ($c['level'] ?? 0),
            'position'        => (int) ($c['position'] ?? 0),
            'parent_id'       => $c['parent_id'] ?? null,
            'path'            => $c['path'] ?? null,
            'description'     => $c['description'] ?? null,
            'include_in_menu' => (bool) ($c['include_in_menu'] ?? true),
            'product_count'   => (int) ($c['product_count'] ?? 0),
        ];
    }
}
