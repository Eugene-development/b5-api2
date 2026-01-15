<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\BonusPaymentRequest;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;

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
     * @param  null  $_
     * @param  array  $args
     * @return bool
     * @throws Error
     */
    public function __invoke(null $_, array $args): bool
    {
        $requestId = $args['request_id'];

        // Находим заявку
        $request = BonusPaymentRequest::with(['status', 'linkedBonuses'])->find($requestId);
        if (!$request) {
            throw new Error('Заявка на выплату не найдена');
        }

        // Проверяем, что заявка не в статусе "paid"
        if ($request->status && $request->status->code === 'paid') {
            throw new Error('Нельзя удалить заявку в статусе "Выплачено"');
        }

        // Удаляем заявку в транзакции
        DB::transaction(function () use ($request) {
            // Удаляем связанные записи о покрытии бонусов
            $request->linkedBonuses()->delete();

            // Удаляем саму заявку
            $request->delete();
        });

        return true;
    }
}
