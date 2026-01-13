<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Bonus;

final readonly class AdminBonusesQuery
{
    /**
     * Get all bonuses for admin panel.
     *
     * @param  null  $_
     * @param  array  $args
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function __invoke(null $_, array $args)
    {
        $query = Bonus::with([
            'status',
            'contract.status',
            'contract.partnerPaymentStatus',
            'contract.project.users',
            'order.partnerPaymentStatus',
            'order.project.users',
            'user'
        ]);

        // Применяем фильтры
        $filters = $args['filters'] ?? [];

        if (!empty($filters['status_code'])) {
            $query->whereHas('status', function ($q) use ($filters) {
                $q->where('code', $filters['status_code']);
            });
        }

        if (!empty($filters['source_type'])) {
            if ($filters['source_type'] === 'contract') {
                $query->whereNotNull('contract_id');
            } elseif ($filters['source_type'] === 'order') {
                $query->whereNotNull('order_id');
            }
        }

        // Фильтр по user_id или agent_id (backward compatibility)
        $userId = $filters['user_id'] ?? $filters['agent_id'] ?? null;
        if (!empty($userId)) {
            $query->where('user_id', $userId);
        }

        // Фильтр по типу получателя
        if (!empty($filters['recipient_type'])) {
            $query->where('recipient_type', $filters['recipient_type']);
        }

        // Фильтр по типу бонуса (legacy)
        if (!empty($filters['bonus_type'])) {
            if ($filters['bonus_type'] === 'agent') {
                $query->where(function ($q) {
                    $q->where('bonus_type', 'agent')
                      ->orWhereNull('bonus_type');
                });
            } elseif ($filters['bonus_type'] === 'referral') {
                $query->where('bonus_type', 'referral');
            }
        }

        if (!empty($filters['date_from'])) {
            $query->where('accrued_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('accrued_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('accrued_at', 'desc')->get();
    }
}
