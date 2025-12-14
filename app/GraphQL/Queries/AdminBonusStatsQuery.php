<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\AgentBonus;
use App\Models\BonusStatus;

final readonly class AdminBonusStatsQuery
{
    /**
     * Get bonus statistics for admin panel.
     *
     * @param  null  $_
     * @param  array  $args
     * @return array
     */
    public function __invoke(null $_, array $args): array
    {
        $filters = $args['filters'] ?? [];

        $query = AgentBonus::query();

        // Apply filters
        if (!empty($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('accrued_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('accrued_at', '<=', $filters['date_to']);
        }

        // Get status IDs
        $accruedStatus = BonusStatus::where('code', 'accrued')->first();
        $availableStatus = BonusStatus::where('code', 'available_for_payment')->first();
        $paidStatus = BonusStatus::where('code', 'paid')->first();

        // Calculate totals
        $totalAccrued = (clone $query)->sum('commission_amount');

        $totalAvailable = (clone $query)
            ->when($availableStatus, fn($q) => $q->where('status_id', $availableStatus->id))
            ->sum('commission_amount');

        $totalPaid = (clone $query)
            ->when($paidStatus, fn($q) => $q->where('status_id', $paidStatus->id))
            ->sum('commission_amount');

        $totalPending = (clone $query)
            ->when($accruedStatus, fn($q) => $q->where('status_id', $accruedStatus->id))
            ->sum('commission_amount');

        // Count by source type
        $contractsCount = (clone $query)->whereNotNull('contract_id')->count();
        $ordersCount = (clone $query)->whereNotNull('order_id')->count();

        return [
            'total_accrued' => (float) $totalAccrued,
            'total_available' => (float) $totalAvailable,
            'total_paid' => (float) $totalPaid,
            'total_pending' => (float) $totalPending,
            'contracts_count' => $contractsCount,
            'orders_count' => $ordersCount,
        ];
    }
}
