<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\BonusPaymentRequest;
use App\Models\BonusPaymentStatus;
use App\Services\BonusPaymentService;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

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
        $this->bonusPaymentService = $bonusPaymentService ?? new BonusPaymentService;
    }

    /**
     * Создать заявку на выплату бонуса.
     *
     * @throws Error
     */
    public function __invoke(null $_, array $args): BonusPaymentRequest
    {
        $input = $args['input'];
        $user = Auth::user();

        if (! $user) {
            throw new Error('Необходима авторизация');
        }

        // Валидация суммы (Property 2: Amount Validation)
        $amount = (float) $input['amount'];
        if ($amount < 1000 || abs($amount * 100 - round($amount * 100)) > 0.00001) {
            throw new Error('Минимальная сумма выплаты — 1 000 ₽, точность — до копеек');
        }

        $statusSlug = $user->status?->slug;
        $requesterType = $input['requester_type']
            ?? ($statusSlug === 'curator'
                ? BonusPaymentRequest::REQUESTER_CURATOR
                : BonusPaymentRequest::REQUESTER_AGENT);

        if (
            $requesterType === BonusPaymentRequest::REQUESTER_CURATOR
            && ! in_array($statusSlug, ['admin', 'curator'], true)
        ) {
            throw new Error('Запрашивать кураторские бонусы может только куратор.');
        }

        // Валидация способа оплаты
        $paymentMethod = $input['payment_method'];
        if (! in_array($paymentMethod, ['card', 'sbp', 'other'])) {
            throw new Error('Недопустимый способ выплаты');
        }

        // Условная валидация полей (Property 3: Conditional Field Validation)
        $this->validatePaymentDetails($paymentMethod, $input);

        // Получаем статус "requested" (Property 1: Default Status Assignment)
        $requestedStatus = BonusPaymentStatus::findByCode('requested');
        if (! $requestedStatus) {
            throw new Error('Статус "requested" не найден в системе');
        }

        // Создаём заявку и связываем бонусы в транзакции
        $request = \App\Services\FinancialLedger::transaction(function () use ($user, $amount, $paymentMethod, $input, $requestedStatus, $requesterType) {
            // Balance validation and reservation must use the same transaction.
            // The selected bonus rows are locked until their links are written.
            $availableBalance = $this->bonusPaymentService->calculateAvailableBalance(
                $user->id,
                $requesterType,
                true
            );
            if ($amount > $availableBalance) {
                throw new Error(
                    'Сумма превышает доступный баланс. Доступно: '.
                    number_format($availableBalance, 2, '.', ' ').' ₽'
                );
            }

            // Создаём заявку
            $request = BonusPaymentRequest::create([
                'agent_id' => $user->id,
                'requester_type' => $requesterType,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'card_number' => $paymentMethod === 'card' ? ($input['card_number'] ?? null) : null,
                'phone_number' => $paymentMethod === 'sbp' ? ($input['phone_number'] ?? null) : null,
                'contact_info' => $paymentMethod === 'other' ? ($input['contact_info'] ?? null) : null,
                'comment' => $input['comment'] ?? null,
                'status_id' => $requestedStatus->id,
            ]);

            // Связываем бонусы с заявкой по FIFO
            $this->bonusPaymentService->linkBonusesToPaymentRequest($request, $user->id, $amount, $requesterType);

            return $request;
        });

        // Загружаем связи для возврата
        $request->load(['agent', 'status', 'linkedBonuses.bonus']);

        return $request;
    }

    /**
     * Валидация реквизитов в зависимости от способа оплаты.
     *
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
