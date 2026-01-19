<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\BonusPaymentRequest;
use GraphQL\Error\Error;

/**
 * Мутация для обновления заявки на выплату бонуса (для админа).
 *
 * Feature: bonus-payments
 */
final readonly class UpdateBonusPaymentRequest
{
    /**
     * Обновить заявку на выплату.
     *
     * @param  null  $_
     * @param  array  $args
     * @return BonusPaymentRequest
     * @throws Error
     */
    public function __invoke(null $_, array $args): BonusPaymentRequest
    {
        $requestId = $args['request_id'];
        $input = $args['input'];

        // Находим заявку
        $request = BonusPaymentRequest::with(['status'])->find($requestId);
        if (!$request) {
            throw new Error('Заявка на выплату не найдена');
        }

        // Определяем, является ли заявка в завершённом статусе
        $isPaid = $request->status && $request->status->code === 'paid';
        $isCancelled = $request->status && $request->status->code === 'cancelled';

        // Для отменённых заявок - полная блокировка
        if ($isCancelled) {
            throw new Error('Нельзя редактировать заявку в статусе "' . $request->status->name . '"');
        }

        // Для выплаченных заявок - разрешаем только изменение даты выплаты
        // (остальные поля просто игнорируются)

        // Подготавливаем данные для обновления
        $updateData = [];

        // Для выплаченных заявок обрабатываем только дату выплаты
        if ($isPaid) {
            if (array_key_exists('payment_date', $input)) {
                $updateData['payment_date'] = $input['payment_date'] 
                    ? \Carbon\Carbon::parse($input['payment_date']) 
                    : null;
            }
        } else {
            // Для остальных статусов - полное редактирование
            if (isset($input['amount'])) {
                $amount = (float) $input['amount'];
                if ($amount < 1000) {
                    throw new Error('Минимальная сумма выплаты — 1 000 ₽');
                }
                $updateData['amount'] = $amount;
            }

            if (isset($input['payment_method'])) {
                $updateData['payment_method'] = $input['payment_method'];
            }

            // Обновляем реквизиты в зависимости от способа оплаты
            $paymentMethod = $input['payment_method'] ?? $request->payment_method;

            if ($paymentMethod === 'card') {
                $updateData['card_number'] = $input['card_number'] ?? null;
                $updateData['phone_number'] = null;
                $updateData['contact_info'] = null;
            } elseif ($paymentMethod === 'sbp') {
                $updateData['phone_number'] = $input['phone_number'] ?? null;
                $updateData['card_number'] = null;
                $updateData['contact_info'] = null;
            } elseif ($paymentMethod === 'other') {
                $updateData['contact_info'] = $input['contact_info'] ?? null;
                $updateData['card_number'] = null;
                $updateData['phone_number'] = null;
            }

            if (isset($input['comment'])) {
                $updateData['comment'] = $input['comment'];
            }

            // Обрабатываем дату выплаты
            if (array_key_exists('payment_date', $input)) {
                $updateData['payment_date'] = $input['payment_date'] 
                    ? \Carbon\Carbon::parse($input['payment_date']) 
                    : null;
            }
        }

        // Обновляем заявку
        $request->update($updateData);

        // Перезагружаем заявку со связями
        $request = BonusPaymentRequest::with(['agent', 'status', 'linkedBonuses.bonus'])->find($requestId);

        return $request;
    }
}
