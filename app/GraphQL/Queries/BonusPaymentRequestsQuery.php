<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\BonusPaymentRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Query для получения заявок на выплату (для админа).
 *
 * Feature: bonus-payments
 * Requirements: 4.1, 4.2, 4.3, 4.4
 */
final readonly class BonusPaymentRequestsQuery
{
    /**
     * Получить все заявки на выплату с пагинацией.
     *
     * @param  null  $_
     * @param  array  $args
     * @return LengthAwarePaginator
     */
    public function __invoke(null $_, array $args): LengthAwarePaginator
    {
        $query = BonusPaymentRequest::with(['agent.phones', 'status']);

        // Применяем фильтры
        $filters = $args['filters'] ?? [];

        // Property 7: Filtering Correctness
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
        $query->orderBy('created_at', 'desc');

        // Property 6: Pagination Consistency
        $perPage = $args['first'] ?? 10;
        $page = $args['page'] ?? 1;

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
