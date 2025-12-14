<?php

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\BonusService;
use Illuminate\Support\Facades\Log;

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

        Log::info('UpdateOrderStatus: Starting', [
            'order_id' => $orderId,
            'status_slug' => $statusSlug,
        ]);

        // Find the order
        $order = Order::findOrFail($orderId);

        // Find the status by slug
        $status = OrderStatus::where('slug', $statusSlug)
            ->where('is_active', true)
            ->firstOrFail();

        // Update the order status
        $order->status_id = $status->id;
        $order->save();

        // Reload with relationships
        $order->load(['project', 'company', 'status', 'partnerPaymentStatus', 'agentBonus']);

        // Обновляем статус бонуса при изменении статуса заказа
        $bonusService = app(BonusService::class);
        $bonusService->handleOrderStatusChange($order, $statusSlug);

        Log::info('UpdateOrderStatus: Success', [
            'order_id' => $order->id,
            'new_status' => $status->value,
        ]);

        return $order;
    }
}
