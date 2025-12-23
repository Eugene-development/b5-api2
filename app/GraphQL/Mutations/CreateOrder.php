<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\Models\OrderPosition;
use App\Models\OrderStatus;
use App\Services\BonusService;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;

final readonly class CreateOrder
{
    /**
     * Create a new order with positions
     *
     * @param  null  $_
     * @param  array{input: array{
     *     value: string,
     *     company_id: string,
     *     project_id: string,
     *     order_number?: string,
     *     delivery_date?: string,
     *     actual_delivery_date?: string,
     *     is_active?: bool,
     *     is_urgent?: bool,
     *     order_amount?: float,
     *     agent_percentage?: float,
     *     curator_percentage?: float,
     *     positions: array<array{
     *         value: string,
     *         article: string,
     *         price: float,
     *         count: int,
     *         supplier?: string,
     *         expected_delivery_date?: string,
     *         actual_delivery_date?: string,
     *         is_active?: bool,
     *         is_urgent?: bool
     *     }>
     * }}  $args
     * @return Order
     */
    public function __invoke(null $_, array $args): Order
    {
        $input = $args['input'];

        // Validate that positions array is not empty
        if (empty($input['positions'])) {
            throw new Error('Order must have at least one position');
        }

        // Check if order number already exists (only if provided)
        if (!empty($input['order_number'])) {
            $existingOrder = Order::where('order_number', $input['order_number'])->first();
            if ($existingOrder) {
                throw new Error('Order with this order number already exists');
            }
        }

        // Use transaction to ensure data consistency
        return DB::transaction(function () use ($input) {
            // Calculate order_amount from positions if not provided
            $orderAmount = $input['order_amount'] ?? null;
            if ($orderAmount === null && !empty($input['positions'])) {
                $orderAmount = 0;
                foreach ($input['positions'] as $positionData) {
                    $price = floatval($positionData['price'] ?? 0);
                    $count = intval($positionData['count'] ?? 0);
                    $orderAmount += $price * $count;
                }
            }

            // Get default order status
            $defaultStatus = OrderStatus::getDefault();

            // Log for debugging
            \Log::info('CreateOrder: Getting default status', [
                'default_status_found' => $defaultStatus !== null,
                'default_status_id' => $defaultStatus?->id,
                'default_status_value' => $defaultStatus?->value,
            ]);

            // If no default status found, get the first active status or create error
            if (!$defaultStatus) {
                \Log::warning('CreateOrder: No default status found, trying first active');

                $defaultStatus = OrderStatus::where('is_active', true)
                    ->orderBy('sort_order')
                    ->first();

                if (!$defaultStatus) {
                    \Log::error('CreateOrder: No active order status found in the system');
                    throw new Error('No active order status found in the system');
                }

                \Log::info('CreateOrder: Using first active status', [
                    'status_id' => $defaultStatus->id,
                    'status_value' => $defaultStatus->value,
                ]);
            }

            // Create the order (order_number will be auto-generated if not provided)
            $order = Order::create([
                'value' => $input['value'],
                'company_id' => $input['company_id'],
                'project_id' => $input['project_id'],
                'order_number' => $input['order_number'] ?? null,
                'delivery_date' => $input['delivery_date'] ?? null,
                'actual_delivery_date' => $input['actual_delivery_date'] ?? null,
                'is_active' => $input['is_active'] ?? true,
                'is_urgent' => $input['is_urgent'] ?? false,
                'order_amount' => $orderAmount,
                'agent_percentage' => $input['agent_percentage'] ?? 5.00,
                'curator_percentage' => $input['curator_percentage'] ?? 5.00,
                'partner_payment_status_id' => 1, // pending по умолчанию
                'status_id' => $defaultStatus->id, // Статус "Сформирован" по умолчанию
            ]);

            \Log::info('CreateOrder: Order created', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status_id' => $order->status_id,
            ]);

            // Create order positions
            foreach ($input['positions'] as $positionData) {
                OrderPosition::create([
                    'order_id' => $order->id,
                    'value' => $positionData['value'],
                    'article' => $positionData['article'],
                    'price' => $positionData['price'],
                    'count' => $positionData['count'],
                    'supplier' => $positionData['supplier'] ?? null,
                    'expected_delivery_date' => $positionData['expected_delivery_date'] ?? null,
                    'actual_delivery_date' => $positionData['actual_delivery_date'] ?? null,
                    'is_active' => $positionData['is_active'] ?? true,
                    'is_urgent' => $positionData['is_urgent'] ?? false,
                ]);
            }

            // Бонус создаётся автоматически в событии Order::created
            // Не нужно создавать вручную, чтобы избежать дубликатов

            // Load relationships and return
            return $order->load(['positions', 'company', 'project', 'status']);
        });
    }
}
