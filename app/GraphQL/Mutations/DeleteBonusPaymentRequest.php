<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\BonusPaymentRequest;
use GraphQL\Error\Error;

/**
 * Мутация для удаления заявки на выплату бонуса (для админа).
 *
 * Feature: bonus-payments
 */
final readonly class DeleteBonusPaymentRequest
{
    /**
     * Удалить заявку на выплату.
     *
     * @throws Error
     */
    public function __invoke(null $_, array $args): bool
    {
        $requestId = $args['request_id'];

        \App\Services\FinancialLedger::transaction(function () use ($requestId): void {
            $request = BonusPaymentRequest::with(['status'])
                ->lockForUpdate()
                ->find($requestId);
            if (! $request) {
                throw new Error('Заявка на выплату не найдена');
            }

            if ($request->status?->code === 'paid') {
                throw new Error('Нельзя удалить заявку в статусе "Выплачено"');
            }

            // Удаляем связанные записи о покрытии бонусов
            $request->linkedBonuses()->delete();

            // Удаляем саму заявку
            $request->delete();
        });

        return true;
    }
}
