<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\AgentBonus;

final readonly class AdminBonusesQuery
{
    /**
     * Get all agent bonuses for admin panel.
     *
     * @param  null  $_
     * @param  array  $args
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function __invoke(null $_, array $args)
    {
        $query = AgentBonus::with([
            'status',
            'contract.status',
            'contract.partnerPaymentStatus',
            'contract.project.users',
            'order.partnerPaymentStatus',
            'order.project.users',
            'agent'
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

        if (!empty($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

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
