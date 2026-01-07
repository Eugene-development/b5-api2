<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\BonusPaymentRequest;
use App\Models\BonusPaymentStatus;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

/**
 * Мутация для создания заявки на выплату бонуса.
 *
 * Feature: bonus-payments
 * Requirements: 3.1, 3.2, 3.4, 3.5, 3.6, 3.7, 3.8, 2.4
 */
final readonly class CreateBonusPaymentRequest
{
    /**
     * Создать заявку на выплату бонуса.
     *
     * @param  null  $_
     * @param  array  $args
     * @return BonusPaymentRequest
     * @throws Error
     */
    public function __invoke(null $_, array $args): BonusPaymentRequest
    {
        $input = $args['input'];
        $user = Auth::user();

        if (!$user) {
            throw new Error('Необходима авторизация');
        }

        // Валидация суммы (Property 2: Amount Validation)
        $amount = (float) $input['amount'];
        if ($amount <= 0) {
            throw new Error('Сумма выплаты должна быть больше нуля');
        }

        // Валидация способа оплаты
        $paymentMethod = $input['payment_method'];
        if (!in_array($paymentMethod, ['card', 'sbp', 'other'])) {
            throw new Error('Недопустимый способ выплаты');
        }

        // Условная валидация полей (Property 3: Conditional Field Validation)
        $this->validatePaymentDetails($paymentMethod, $input);

        // Получаем статус "requested" (Property 1: Default Status Assignment)
        $requestedStatus = BonusPaymentStatus::findByCode('requested');
        if (!$requestedStatus) {
            throw new Error('Статус "requested" не найден в системе');
        }

        // Создаём заявку
        $request = BonusPaymentRequest::create([
            'agent_id' => $user->id,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'card_number' => $paymentMethod === 'card' ? ($input['card_number'] ?? null) : null,
            'phone_number' => $paymentMethod === 'sbp' ? ($input['phone_number'] ?? null) : null,
            'contact_info' => $paymentMethod === 'other' ? ($input['contact_info'] ?? null) : null,
            'comment' => $input['comment'] ?? null,
            'status_id' => $requestedStatus->id,
        ]);

        // Загружаем связи для возврата
        $request->load(['agent', 'status']);

        return $request;
    }

    /**
     * Валидация реквизитов в зависимости от способа оплаты.
     *
     * @param string $paymentMethod
     * @param array $input
     * @throws Error
     */
    private function validatePaymentDetails(string $paymentMethod, array $input): void
    {
        switch ($paymentMethod) {
            case 'card':
                if (empty($input['card_number'])) {
                    throw new Error('Для способа оплаты "Карта" необходимо указать номер карты');
                }
                break;

            case 'sbp':
                if (empty($input['phone_number'])) {
                    throw new Error('Для способа оплаты "СБП" необходимо указать номер телефона');
                }
                break;

            case 'other':
                if (empty($input['contact_info'])) {
                    throw new Error('Для способа оплаты "Другое" необходимо указать контактную информацию');
                }
                break;
        }
    }
}
