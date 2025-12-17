<?php

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\Models\OrderStatus;

class UpdateOrderStatus
{
    /**
     * Update the status of an order.
     *
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $args): Order
    {
        $orderId = $args['order_id'];
        $statusSlug = $args['status_slug'];

        // Find the order
        $order = Order::findOrFail($orderId);

        // Find the status by slug
        $status = OrderStatus::where('slug', $statusSlug)
            ->where('is_active', true)
            ->firstOrFail();

        // Update the order status directly without triggering model events
        Order::where('id', $orderId)->update(['status_id' => $status->id]);

        // Reload the order with fresh data
        $order = Order::with(['project', 'company', 'status'])->findOrFail($orderId);

        return $order;
    }
}
