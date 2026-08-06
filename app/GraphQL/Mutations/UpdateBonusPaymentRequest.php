<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\BonusPaymentRequest;
use App\Services\BonusPaymentService;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;

/**
 * Мутация для обновления заявки на выплату бонуса (для админа).
 *
 * Feature: bonus-payments
 */
final readonly class UpdateBonusPaymentRequest
{
    public function __construct(private BonusPaymentService $bonusPaymentService) {}

    /**
     * Обновить заявку на выплату.
     *
     * @throws Error
     */
    public function __invoke(null $_, array $args): BonusPaymentRequest
    {
        $requestId = $args['request_id'];
        $input = $args['input'];

        return DB::transaction(function () use ($requestId, $input): BonusPaymentRequest {
            // Находим заявку и блокируем её от параллельного изменения
            $request = BonusPaymentRequest::with(['status'])->lockForUpdate()->find($requestId);
            if (! $request) {
                throw new Error('Заявка на выплату не найдена');
            }

            // Определяем, является ли заявка в завершённом статусе
            $isPaid = $request->status && $request->status->code === 'paid';
            $isCancelled = $request->status && $request->status->code === 'cancelled';

            // Для отменённых заявок - полная блокировка
            if ($isCancelled) {
                throw new Error('Нельзя редактировать заявку в статусе "'.$request->status->name.'"');
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
                if (! in_array($paymentMethod, ['card', 'sbp', 'other'], true)) {
                    throw new Error('Недопустимый способ выплаты');
                }

                $paymentMethodChanged = array_key_exists('payment_method', $input)
                    && $paymentMethod !== $request->payment_method;

                if ($paymentMethod === 'card') {
                    if (($paymentMethodChanged || array_key_exists('card_number', $input)) && empty($input['card_number'])) {
                        throw new Error('Для способа оплаты "Карта" необходимо указать номер карты');
                    }
                    if (array_key_exists('card_number', $input)) {
                        $updateData['card_number'] = $input['card_number'];
                    }
                } elseif ($paymentMethod === 'sbp') {
                    if (($paymentMethodChanged || array_key_exists('phone_number', $input)) && empty($input['phone_number'])) {
                        throw new Error('Для способа оплаты "СБП" необходимо указать номер телефона');
                    }
                    if (array_key_exists('phone_number', $input)) {
                        $updateData['phone_number'] = $input['phone_number'];
                    }
                } elseif ($paymentMethod === 'other') {
                    if (($paymentMethodChanged || array_key_exists('contact_info', $input)) && empty($input['contact_info'])) {
                        throw new Error('Для способа оплаты "Другое" необходимо указать контактную информацию');
                    }
                    if (array_key_exists('contact_info', $input)) {
                        $updateData['contact_info'] = $input['contact_info'];
                    }
                }

                if ($paymentMethodChanged) {
                    $updateData['card_number'] = $paymentMethod === 'card' ? $input['card_number'] : null;
                    $updateData['phone_number'] = $paymentMethod === 'sbp' ? $input['phone_number'] : null;
                    $updateData['contact_info'] = $paymentMethod === 'other' ? $input['contact_info'] : null;
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

            $amountChanged = array_key_exists('amount', $updateData)
                && (float) $updateData['amount'] !== (float) $request->amount;

            if ($amountChanged) {
                // Release the old reservation inside the same transaction. If the new
                // amount cannot be reserved, the transaction restores the old links.
                $request->linkedBonuses()->delete();
            }

            $request->update($updateData);

            if ($amountChanged) {
                try {
                    $this->bonusPaymentService->linkBonusesToPaymentRequest(
                        $request,
                        (int) $request->agent_id,
                        (float) $request->amount,
                        $request->requester_type
                    );
                } catch (\DomainException $exception) {
                    throw new Error($exception->getMessage());
                }
            }

            // Перезагружаем заявку со связями
            $request = BonusPaymentRequest::with(['agent', 'status', 'linkedBonuses.bonus'])->find($requestId);

            return $request;
        });
    }
}
