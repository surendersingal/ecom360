<?php

declare(strict_types=1);

namespace Modules\DataSync\Services\Normalizers;

use Modules\DataSync\Enums\Platform;

/**
 * Normalizes customer data from different e-commerce platforms.
 */
final class CustomerNormalizer
{
    public function normalize(array $raw, Platform $platform): array
    {
        return match ($platform) {
            Platform::Magento2    => $this->fromMagento($raw),
            Platform::WooCommerce => $this->fromWooCommerce($raw),
            default               => $this->fromGeneric($raw),
        };
    }

    public function normalizeBatch(array $customers, Platform $platform): array
    {
        return array_map(fn (array $c) => $this->normalize($c, $platform), $customers);
    }

    private function fromMagento(array $c): array
    {
        return [
            'external_id' => (string) ($c['id'] ?? $c['entity_id'] ?? ''),
            'email'        => (string) ($c['email'] ?? ''),
            'firstname'    => $c['firstname'] ?? null,
            'lastname'     => $c['lastname'] ?? null,
            'name'         => $c['name'] ?? trim(($c['firstname'] ?? '') . ' ' . ($c['lastname'] ?? '')),
            'dob'          => $c['dob'] ?? null,
            'gender'       => isset($c['gender']) ? (int) $c['gender'] : null,
            'group_id'     => isset($c['group_id']) ? (int) $c['group_id'] : null,
            'attributes'   => $c['attributes'] ?? [],
        ];
    }

    private function fromWooCommerce(array $c): array
    {
        return [
            'external_id' => (string) ($c['id'] ?? $c['customer_id'] ?? ''),
            'email'        => (string) ($c['email'] ?? ''),
            'firstname'    => $c['first_name'] ?? $c['billing']['first_name'] ?? null,
            'lastname'     => $c['last_name'] ?? $c['billing']['last_name'] ?? null,
            'name'         => $c['name'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
            'dob'          => $c['date_of_birth'] ?? null,
            'gender'       => null,
            'group_id'     => null,
            'attributes'   => [
                'role'          => $c['role'] ?? 'customer',
                'orders_count'  => $c['orders_count'] ?? null,
                'total_spent'   => $c['total_spent'] ?? null,
            ],
        ];
    }

    private function fromGeneric(array $c): array
    {
        return [
            'external_id' => (string) ($c['id'] ?? $c['external_id'] ?? ''),
            'email'        => (string) ($c['email'] ?? ''),
            'firstname'    => $c['firstname'] ?? $c['first_name'] ?? null,
            'lastname'     => $c['lastname'] ?? $c['last_name'] ?? null,
            'name'         => $c['name'] ?? '',
            'dob'          => $c['dob'] ?? null,
            'gender'       => $c['gender'] ?? null,
            'group_id'     => $c['group_id'] ?? null,
            'attributes'   => $c['attributes'] ?? [],
        ];
    }
}
