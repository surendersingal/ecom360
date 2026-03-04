<?php
declare(strict_types=1);

namespace Modules\Chatbot\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OrderTrackingService — Real-time order status for chatbot.
 *
 * Powers Use Cases:
 *   - AI Chatbot Conversational Checkout (UC20)
 *   - Order tracking queries
 */
class OrderTrackingService
{
    /**
     * Get order status from synced order data.
     */
    public function getOrderStatus(int $tenantId, string $orderId): array
    {
        try {
            // Look in purchase events
            $event = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where(function ($q) use ($orderId) {
                    $q->where('metadata.order_id', $orderId)
                      ->orWhere('metadata.order_id', '#' . $orderId)
                      ->orWhere('metadata.increment_id', $orderId);
                })
                ->first();

            if (!$event) {
                return ['found' => false, 'order_id' => $orderId];
            }

            // Check for shipment event
            $shipment = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'order_shipped')
                ->where('metadata.order_id', $orderId)
                ->first();

            // Check for delivery event
            $delivered = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'order_delivered')
                ->where('metadata.order_id', $orderId)
                ->first();

            // Check for refund
            $refund = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'refund')
                ->where('metadata.order_id', $orderId)
                ->first();

            // Determine status
            $status = 'processing';
            $statusDetail = 'Your order is being prepared.';
            $estimatedDelivery = 'Within 5-7 business days';
            $trackingUrl = null;

            if ($refund) {
                $status = 'refunded';
                $statusDetail = 'This order has been refunded.';
                $estimatedDelivery = 'N/A';
            } elseif ($delivered) {
                $status = 'delivered';
                $statusDetail = 'Your order has been delivered!';
                $estimatedDelivery = 'Delivered on ' . ($delivered['created_at'] ?? 'recently');
            } elseif ($shipment) {
                $status = 'shipped';
                $statusDetail = 'Your order is on its way!';
                $trackingUrl = $shipment['metadata']['tracking_url'] ?? null;
                $trackingNumber = $shipment['metadata']['tracking_number'] ?? null;
                $carrier = $shipment['metadata']['carrier'] ?? 'carrier';

                if ($trackingNumber) {
                    $statusDetail .= " Tracking: {$trackingNumber} via {$carrier}.";
                }

                // Estimate delivery
                $shippedAt = $shipment['created_at'] ?? now()->toDateTimeString();
                $estDate = now()->parse($shippedAt)->addDays(5)->format('M j, Y');
                $estimatedDelivery = "Expected by {$estDate}";
            } else {
                // Check order status from metadata
                $orderStatus = $event['metadata']['status'] ?? 'processing';
                $status = $orderStatus;
                $statusDetail = match ($orderStatus) {
                    'pending'    => 'Your order is pending payment confirmation.',
                    'processing' => 'Your order is being prepared for shipment.',
                    'complete'   => 'Your order has been completed.',
                    'canceled'   => 'This order was canceled.',
                    'holded'     => 'Your order is on hold. Please contact support.',
                    default      => "Order status: {$orderStatus}",
                };
            }

            return [
                'found'              => true,
                'order_id'           => $orderId,
                'status'             => $status,
                'status_detail'      => $statusDetail,
                'estimated_delivery' => $estimatedDelivery,
                'tracking_url'       => $trackingUrl,
                'order_date'         => $event['created_at'] ?? null,
                'total'              => $event['metadata']['total'] ?? null,
                'items'              => $event['metadata']['items'] ?? [],
                'item_count'         => count($event['metadata']['items'] ?? []),
            ];
        } catch (\Exception $e) {
            Log::error("OrderTrackingService::getOrderStatus error: {$e->getMessage()}");
            return ['found' => false, 'order_id' => $orderId, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get recent orders for a customer.
     */
    public function getRecentOrders(int $tenantId, string $email, int $limit = 5): array
    {
        try {
            $orders = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('customer_identifier.value', $email)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return [
                'orders' => $orders->map(fn($o) => [
                    'order_id'   => $o['metadata']['order_id'] ?? 'N/A',
                    'total'      => $o['metadata']['total'] ?? 0,
                    'status'     => $o['metadata']['status'] ?? 'processing',
                    'item_count' => count($o['metadata']['items'] ?? []),
                    'date'       => $o['created_at'] ?? null,
                ])->toArray(),
                'count' => $orders->count(),
            ];
        } catch (\Exception $e) {
            Log::error("OrderTrackingService::getRecentOrders error: {$e->getMessage()}");
            return ['orders' => [], 'count' => 0, 'error' => $e->getMessage()];
        }
    }
}
