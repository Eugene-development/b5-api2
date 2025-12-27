<?php

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PartnerPaymentStatus;
use App\Services\BonusService;

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

        // Prepare update data
        $updateData = ['status_id' => $status->id];

        // When order status changes to 'delivered', automatically set partner_payment_status to 'paid'
        if ($statusSlug === 'delivered') {
            $paidStatus = PartnerPaymentStatus::where('code', 'paid')->first();
            if ($paidStatus) {
                $updateData['partner_payment_status_id'] = $paidStatus->id;
                $updateData['partner_payment_date'] = now();
            }
        }

        // Update the order status directly without triggering model events
        Order::where('id', $orderId)->update($updateData);

        // Reload the order with fresh data
        $order = Order::with(['project', 'company', 'status', 'agentBonus', 'partnerPaymentStatus'])->findOrFail($orderId);

        // Handle bonus status change based on order status
        // When order status changes to 'delivered', bonus becomes available for payment
        $bonusService = new BonusService();
        $bonusService->handleOrderStatusChange($order, $statusSlug);

        return $order;
    }
}
