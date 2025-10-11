<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\Models\OrderPosition;
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
     *     order_number: string,
     *     delivery_date?: string,
     *     actual_delivery_date?: string,
     *     is_active?: bool,
     *     is_urgent?: bool,
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

            // Load relationships and return
            return $order->load(['positions', 'company', 'project']);
        });
    }
}
