<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\Models\PartnerPaymentStatus;
use App\Services\BonusService;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;

final readonly class UpdateOrderPartnerPaymentStatus
{
    /**
     * Update partner payment status for an order.
     *
     * @param  null  $_
     * @param  array  $args
     * @return Order
     */
    public function __invoke(null $_, array $args): Order
    {
        $orderId = $args['order_id'];
        $statusCode = $args['status_code'];

        $status = PartnerPaymentStatus::findByCode($statusCode);
        if (!$status) {
            throw new Error("Неизвестный статус: {$statusCode}");
        }

        return DB::transaction(function () use ($orderId, $status, $statusCode) {
            $order = Order::findOrFail($orderId);
            $order->partner_payment_status_id = $status->id;

            // Устанавливаем дату оплаты при смене статуса на "paid"
            if ($statusCode === 'paid') {
                $order->partner_payment_date = now()->toDateString();
            } elseif ($statusCode === 'pending') {
                // Сбрасываем дату при возврате в статус "ожидание"
                $order->partner_payment_date = null;
            }

            $order->save();

            // ПРИМЕЧАНИЕ: С упрощением статусов бонусов, статус оплаты партнёром
            // больше не влияет на статус бонуса. Бонус остаётся в статусе "Ожидание"
            // до момента выплаты агенту.

            return $order->load(['project', 'company', 'partnerPaymentStatus']);
        });
    }
}
