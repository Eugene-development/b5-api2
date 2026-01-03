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
        $pendingStatus = BonusStatus::where('code', 'pending')->first();
        $paidStatus = BonusStatus::where('code', 'paid')->first();

        // Calculate totals
        $totalPending = (clone $query)
            ->when($pendingStatus, fn($q) => $q->where('status_id', $pendingStatus->id))
            ->sum('commission_amount');

        // Calculate available bonuses (pending status + available_at <= now)
        $totalAvailable = (clone $query)
            ->when($pendingStatus, fn($q) => $q->where('status_id', $pendingStatus->id))
            ->where('available_at', '<=', now())
            ->sum('commission_amount');

        $totalPaid = (clone $query)
            ->when($paidStatus, fn($q) => $q->where('status_id', $paidStatus->id))
            ->sum('commission_amount');

        // Count by source type
        $contractsCount = (clone $query)->whereNotNull('contract_id')->count();
        $ordersCount = (clone $query)->whereNotNull('order_id')->count();

        return [
            'total_pending' => (float) $totalPending,
            'total_available' => (float) $totalAvailable,
            'total_paid' => (float) $totalPaid,
            'contracts_count' => $contractsCount,
            'orders_count' => $ordersCount,
        ];
    }
}
