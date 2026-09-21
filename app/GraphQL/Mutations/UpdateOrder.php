<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Order;

final readonly class UpdateOrder
{
    /**
     * Update an order with automatic bonus recalculation.
     */
    public function __invoke(null $_, array $args): Order
    {
        $input = $args['input'] ?? $args;
        $orderId = $input['id'];

        return \App\Services\FinancialLedger::transaction(function () use ($input, $orderId) {
            $order = Order::findOrFail($orderId);

            // Запоминаем предыдущее значение is_active
            $previousIsActive = $order->is_active;

            // Обновляем поля заказа
            $order->fill(array_filter([
                'value' => $input['value'] ?? null,
                'company_id' => $input['company_id'] ?? null,
                'project_id' => $input['project_id'] ?? null,
                'order_number' => $input['order_number'] ?? null,
                'delivery_date' => $input['delivery_date'] ?? null,
                'actual_delivery_date' => $input['actual_delivery_date'] ?? null,
                'order_amount' => $input['order_amount'] ?? null,
                'agent_percentage' => $input['agent_percentage'] ?? null,
                'curator_percentage' => $input['curator_percentage'] ?? null,
                'is_active' => $input['is_active'] ?? null,
                'is_urgent' => $input['is_urgent'] ?? null,
            ], fn ($value) => $value !== null));

            $order->save();

            return $order->load(['project', 'company', 'status', 'partnerPaymentStatus']);
        });
    }
}
