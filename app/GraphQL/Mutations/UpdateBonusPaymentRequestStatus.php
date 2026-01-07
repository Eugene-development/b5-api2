<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\BonusPaymentRequest;
use App\Models\BonusPaymentStatus;
use GraphQL\Error\Error;

/**
 * Мутация для обновления статуса заявки на выплату бонуса.
 *
 * Feature: bonus-payments
 * Requirements: 5.1, 5.2, 5.3, 5.4
 */
final readonly class UpdateBonusPaymentRequestStatus
{
    /**
     * Обновить статус заявки на выплату.
     *
     * @param  null  $_
     * @param  array  $args
     * @return BonusPaymentRequest
     * @throws Error
     */
    public function __invoke(null $_, array $args): BonusPaymentRequest
    {
        $requestId = $args['request_id'];
        $statusCode = $args['status_code'];

        // Находим заявку
        $request = BonusPaymentRequest::find($requestId);
        if (!$request) {
            throw new Error('Заявка на выплату не найдена');
        }

        // Валидация статуса (Property 5: Valid Status Transition)
        $status = BonusPaymentStatus::findByCode($statusCode);
        if (!$status) {
            throw new Error("Статус '{$statusCode}' не найден в системе");
        }

        // Подготавливаем данные для обновления
        $updateData = ['status_id' => $status->id];

        // Property 4: Status Update with Payment Date
        // При переходе в статус "paid" автоматически устанавливаем дату выплаты
        if ($statusCode === 'paid') {
            $updateData['payment_date'] = now();
        } else {
            // При переходе в любой другой статус очищаем дату выплаты
            $updateData['payment_date'] = null;
        }

        // Обновляем заявку
        $request->update($updateData);

        // Перезагружаем заявку со связями
        $request = BonusPaymentRequest::with(['agent', 'status'])->find($requestId);

        return $request;
    }
}
