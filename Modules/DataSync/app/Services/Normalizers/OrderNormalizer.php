<?php

declare(strict_types=1);

namespace Modules\DataSync\Services\Normalizers;

use Modules\DataSync\Enums\Platform;

/**
 * Normalizes order data from different e-commerce platforms.
 */
final class OrderNormalizer
{
    public function normalize(array $raw, Platform $platform): array
    {
        return match ($platform) {
            Platform::Magento2    => $this->fromMagento($raw),
            Platform::WooCommerce => $this->fromWooCommerce($raw),
            default               => $this->fromGeneric($raw),
        };
    }

    public function normalizeBatch(array $orders, Platform $platform): array
    {
        return array_map(fn (array $o) => $this->normalize($o, $platform), $orders);
    }

    private function fromMagento(array $o): array
    {
        return [
            'external_id'     => (string) ($o['entity_id'] ?? $o['order_id'] ?? ''),
            'order_number'    => (string) ($o['order_id'] ?? $o['increment_id'] ?? ''),
            'status'          => (string) ($o['status'] ?? ''),
            'state'           => $o['state'] ?? null,
            'grand_total'     => (float) ($o['grand_total'] ?? 0),
            'subtotal'        => (float) ($o['subtotal'] ?? 0),
            'tax_amount'      => (float) ($o['tax_amount'] ?? 0),
            'shipping_amount' => (float) ($o['shipping_amount'] ?? 0),
            'discount_amount' => (float) ($o['discount_amount'] ?? 0),
            'total_qty'       => (int) ($o['total_qty'] ?? $o['total_qty_ordered'] ?? 0),
            'currency'        => (string) ($o['currency'] ?? $o['order_currency_code'] ?? 'USD'),
            'payment_method'  => $o['payment_method'] ?? null,
            'shipping_method' => $o['shipping_method'] ?? null,
            'coupon_code'     => $o['coupon_code'] ?? null,
            'customer_email'  => $o['customer_email'] ?? null,
            'customer_id'     => isset($o['customer_id']) ? (string) $o['customer_id'] : null,
            'is_guest'        => (bool) ($o['is_guest'] ?? $o['customer_is_guest'] ?? false),
            'items'           => $this->normalizeMagentoItems($o['items'] ?? []),
            'billing_address' => $o['billing_address'] ?? null,
            'shipping_address' => $o['shipping_address'] ?? null,
        ];
    }

    private function fromWooCommerce(array $o): array
    {
        return [
            'external_id'     => (string) ($o['id'] ?? $o['order_id'] ?? ''),
            'order_number'    => (string) ($o['number'] ?? $o['order_number'] ?? $o['id'] ?? ''),
            'status'          => (string) ($o['status'] ?? ''),
            'state'           => null,
            'grand_total'     => (float) ($o['total'] ?? 0),
            'subtotal'        => (float) ($o['subtotal'] ?? 0),
            'tax_amount'      => (float) ($o['total_tax'] ?? 0),
            'shipping_amount' => (float) ($o['shipping_total'] ?? 0),
            'discount_amount' => (float) ($o['discount_total'] ?? 0),
            'total_qty'       => $this->sumWooItemQty($o['line_items'] ?? $o['items'] ?? []),
            'currency'        => (string) ($o['currency'] ?? 'USD'),
            'payment_method'  => $o['payment_method'] ?? null,
            'shipping_method' => $o['shipping_method'] ?? ($o['shipping_lines'][0]['method_title'] ?? null),
            'coupon_code'     => $o['coupon_lines'][0]['code'] ?? ($o['coupons'][0] ?? null),
            'customer_email'  => $o['billing']['email'] ?? $o['customer_email'] ?? null,
            'customer_id'     => isset($o['customer_id']) ? (string) $o['customer_id'] : null,
            'is_guest'        => ($o['customer_id'] ?? 0) == 0,
            'items'           => $this->normalizeWooItems($o['line_items'] ?? $o['items'] ?? []),
            'billing_address' => $o['billing'] ?? null,
            'shipping_address' => $o['shipping'] ?? null,
        ];
    }

    private function fromGeneric(array $o): array
    {
        return [
            'external_id'      => (string) ($o['id'] ?? $o['external_id'] ?? ''),
            'order_number'     => (string) ($o['order_number'] ?? $o['id'] ?? ''),
            'status'           => (string) ($o['status'] ?? ''),
            'state'            => $o['state'] ?? null,
            'grand_total'      => (float) ($o['grand_total'] ?? $o['total'] ?? 0),
            'subtotal'         => (float) ($o['subtotal'] ?? 0),
            'tax_amount'       => (float) ($o['tax_amount'] ?? $o['tax'] ?? 0),
            'shipping_amount'  => (float) ($o['shipping_amount'] ?? $o['shipping'] ?? 0),
            'discount_amount'  => (float) ($o['discount_amount'] ?? $o['discount'] ?? 0),
            'total_qty'        => (int) ($o['total_qty'] ?? 0),
            'currency'         => (string) ($o['currency'] ?? 'USD'),
            'payment_method'   => $o['payment_method'] ?? null,
            'shipping_method'  => $o['shipping_method'] ?? null,
            'coupon_code'      => $o['coupon_code'] ?? null,
            'customer_email'   => $o['customer_email'] ?? null,
            'customer_id'      => $o['customer_id'] ?? null,
            'is_guest'         => (bool) ($o['is_guest'] ?? false),
            'items'            => $o['items'] ?? [],
            'billing_address'  => $o['billing_address'] ?? null,
            'shipping_address' => $o['shipping_address'] ?? null,
        ];
    }

    private function normalizeMagentoItems(array $items): array
    {
        return array_map(fn (array $i) => [
            'product_id' => (string) ($i['product_id'] ?? ''),
            'sku'        => (string) ($i['sku'] ?? ''),
            'name'       => (string) ($i['name'] ?? ''),
            'qty'        => (int) ($i['qty'] ?? $i['qty_ordered'] ?? 0),
            'price'      => (float) ($i['price'] ?? 0),
            'row_total'  => (float) ($i['row_total'] ?? 0),
            'discount'   => (float) ($i['discount_amount'] ?? $i['discount'] ?? 0),
        ], $items);
    }

    private function normalizeWooItems(array $items): array
    {
        return array_map(fn (array $i) => [
            'product_id' => (string) ($i['product_id'] ?? $i['id'] ?? ''),
            'sku'        => (string) ($i['sku'] ?? ''),
            'name'       => (string) ($i['name'] ?? ''),
            'qty'        => (int) ($i['quantity'] ?? $i['qty'] ?? 0),
            'price'      => (float) ($i['price'] ?? 0),
            'row_total'  => (float) ($i['total'] ?? $i['subtotal'] ?? 0),
            'discount'   => (float) ($i['discount'] ?? 0),
        ], $items);
    }

    private function sumWooItemQty(array $items): int
    {
        return (int) array_sum(array_map(fn ($i) => $i['quantity'] ?? $i['qty'] ?? 0, $items));
    }
}
