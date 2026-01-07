<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\BonusPaymentRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;

/**
 * Query для получения заявок на выплату текущего агента.
 *
 * Feature: bonus-payments
 */
final readonly class MyBonusPaymentRequestsQuery
{
    /**
     * Получить заявки на выплату текущего пользователя.
     *
     * @param  null  $_
     * @param  array  $args
     * @return Collection
     */
    public function __invoke(null $_, array $args): Collection
    {
        $user = Auth::user();

        if (!$user) {
            return collect();
        }

        $query = BonusPaymentRequest::with(['status'])
            ->where('agent_id', $user->id);

        // Применяем фильтры
        $filters = $args['filters'] ?? [];

        if (!empty($filters['status_id'])) {
            $query->where('status_id', $filters['status_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        // Сортировка по дате создания (новые первыми)
        return $query->orderBy('created_at', 'desc')->get();
    }
}
