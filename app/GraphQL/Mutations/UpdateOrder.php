<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\Services\BonusService;
use Illuminate\Support\Facades\DB;

final readonly class UpdateOrder
{
    /**
     * Update an order with automatic bonus recalculation.
     *
     * @param  null  $_
     * @param  array  $args
     * @return Order
     */
    public function __invoke(null $_, array $args): Order
    {
        $input = $args['input'] ?? $args;
        $orderId = $input['id'];

        return DB::transaction(function () use ($input, $orderId) {
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
            ], fn($value) => $value !== null));

            $order->save();

            // Пересчитываем бонус агента если он существует
            $bonusService = app(BonusService::class);
            if ($order->agentBonus) {
                $bonusService->recalculateBonus($order->agentBonus);

                // Если изменился is_active, обрабатываем изменение статуса бонуса
                if (isset($input['is_active']) && $previousIsActive !== $order->is_active) {
                    $order->load(['status', 'partnerPaymentStatus']);
                    $bonusService->handleOrderActiveChange($order);
                }
            }

            return $order->load(['project', 'company', 'status', 'partnerPaymentStatus']);
        });
    }
}
