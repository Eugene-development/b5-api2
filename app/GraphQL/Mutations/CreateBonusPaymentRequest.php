<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\BonusPaymentRequest;
use App\Models\BonusPaymentStatus;
use App\Services\BonusPaymentService;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Мутация для создания заявки на выплату бонуса.
 *
 * Feature: bonus-payments
 * Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 2.4, 8.3, 11.2, 11.4
 */
final readonly class CreateBonusPaymentRequest
{
    private BonusPaymentService $bonusPaymentService;

    public function __construct(?BonusPaymentService $bonusPaymentService = null)
    {
        $this->bonusPaymentService = $bonusPaymentService ?? new BonusPaymentService();
    }

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

        // Валидация суммы против доступного баланса (Property 7: Balance Validation)
        $availableBalance = $this->bonusPaymentService->calculateAvailableBalance($user->id);
        if ($amount > $availableBalance) {
            throw new Error(
                "Сумма превышает доступный баланс. Доступно: " .
                number_format($availableBalance, 2, '.', ' ') . " ₽"
            );
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

        // Создаём заявку и связываем бонусы в транзакции
        $request = DB::transaction(function () use ($user, $amount, $paymentMethod, $input, $requestedStatus) {
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

            // Связываем бонусы с заявкой по FIFO
            $this->bonusPaymentService->linkBonusesToPaymentRequest($request, $user->id, $amount);

            return $request;
        });

        // Загружаем связи для возврата
        $request->load(['agent', 'status', 'linkedBonuses.bonus']);

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
