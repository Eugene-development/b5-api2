<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\BonusPaymentRequest;
use App\Models\BonusPaymentStatus;
use App\Services\BonusPaymentService;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;

/**
 * Мутация для обновления статуса заявки на выплату бонуса.
 *
 * Feature: bonus-payments
 * Requirements: 5.1, 5.2, 5.3, 5.4, 10.1, 10.5
 */
final readonly class UpdateBonusPaymentRequestStatus
{
    private BonusPaymentService $bonusPaymentService;

    public function __construct(?BonusPaymentService $bonusPaymentService = null)
    {
        $this->bonusPaymentService = $bonusPaymentService ?? new BonusPaymentService;
    }

    /**
     * Обновить статус заявки на выплату.
     *
     * @throws Error
     */
    public function __invoke(null $_, array $args): BonusPaymentRequest
    {
        $requestId = $args['request_id'];
        $statusCode = $args['status_code'];

        $request = DB::transaction(function () use ($requestId, $statusCode): BonusPaymentRequest {
            // Lock before reading the current status so concurrent transitions cannot
            // settle or roll back the same bonuses twice.
            $request = BonusPaymentRequest::with(['status'])
                ->lockForUpdate()
                ->find($requestId);
            if (! $request) {
                throw new Error('Заявка на выплату не найдена');
            }

            $newStatus = BonusPaymentStatus::findByCode($statusCode);
            if (! $newStatus) {
                throw new Error("Статус '{$statusCode}' не найден в системе");
            }

            $currentStatusCode = $request->status?->code;
            $isTransitionToPaid = $statusCode === 'paid' && $currentStatusCode !== 'paid';
            $isTransitionFromPaid = $currentStatusCode === 'paid' && $statusCode !== 'paid';

            // Подготавливаем данные для обновления
            $updateData = ['status_id' => $newStatus->id];

            // Property 4: Status Update with Payment Date
            if ($statusCode === 'paid') {
                $updateData['payment_date'] = now();
            } else {
                $updateData['payment_date'] = null;
            }

            // Обновляем заявку
            $request->update($updateData);

            // Автоматическое погашение бонусов при переходе в статус "paid"
            if ($isTransitionToPaid) {
                $this->bonusPaymentService->settleBonuses($request);
            }

            // Откат погашения при переходе из статуса "paid"
            if ($isTransitionFromPaid) {
                $this->bonusPaymentService->rollbackSettlement($request);
            }

            return $request;
        });

        // Перезагружаем заявку со связями
        return $request->fresh(['agent', 'status', 'linkedBonuses.bonus']);
    }
}
