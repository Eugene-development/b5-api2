<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Bonus;
use App\Models\BonusStatus;

final readonly class AdminBonusStatsQuery
{
    /**
     * Get bonus statistics for admin panel.
     *
     * Статистика рассчитывается на основе условий доступности:
     * - Для договоров: is_contract_completed И is_partner_paid И is_active
     * - Для заказов: status = 'delivered' И is_active
     *
     * @param  null  $_
     * @param  array  $args
     * @return array
     */
    public function __invoke(null $_, array $args): array
    {
        $filters = $args['filters'] ?? [];

        $query = Bonus::with(['contract.status', 'contract.partnerPaymentStatus', 'order.status']);

        // Apply filters
        $userId = $filters['user_id'] ?? $filters['agent_id'] ?? null;
        if (!empty($userId)) {
            $query->where('user_id', $userId);
        }

        if (!empty($filters['recipient_type'])) {
            $query->where('recipient_type', $filters['recipient_type']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('accrued_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('accrued_at', '<=', $filters['date_to']);
        }

        $bonuses = $query->get();

        $totalPending = 0.0;
        $totalAvailable = 0.0;
        $totalPaid = 0.0;
        $totalReferral = 0.0;
        $referralCount = 0;
        $totalAgent = 0.0;
        $agentCount = 0;
        $totalCurator = 0.0;
        $curatorCount = 0;
        $contractsCount = 0;
        $ordersCount = 0;

        foreach ($bonuses as $bonus) {
            $amount = (float) $bonus->commission_amount;

            // Count by source type
            if ($bonus->contract_id) {
                $contractsCount++;
            }
            if ($bonus->order_id) {
                $ordersCount++;
            }

            // Count by recipient type
            if ($bonus->recipient_type === Bonus::RECIPIENT_AGENT) {
                $totalAgent += $amount;
                $agentCount++;
            } elseif ($bonus->recipient_type === Bonus::RECIPIENT_CURATOR) {
                $totalCurator += $amount;
                $curatorCount++;
            } elseif ($bonus->recipient_type === Bonus::RECIPIENT_REFERRER) {
                $totalReferral += $amount;
                $referralCount++;
            }

            // Выплачено: бонусы с paid_at
            if ($bonus->paid_at !== null) {
                $totalPaid += $amount;
                continue;
            }

            // Определяем доступность бонуса к выплате
            $isAvailable = false;

            if ($bonus->contract_id && $bonus->contract) {
                // Для договоров: проверяем is_contract_completed И is_partner_paid
                $contract = $bonus->contract;
                $isContractCompleted = $contract->status && $contract->status->slug === 'completed';
                $isPartnerPaid = $contract->partnerPaymentStatus && $contract->partnerPaymentStatus->code === 'paid';
                $isContractActive = $contract->is_active === true;

                $isAvailable = $isContractCompleted && $isPartnerPaid && $isContractActive;
            } elseif ($bonus->order_id && $bonus->order) {
                // Для заказов: проверяем статус доставки
                $order = $bonus->order;
                $isOrderDelivered = $order->status && $order->status->slug === 'delivered';
                $isOrderActive = $order->is_active === true;

                $isAvailable = $isOrderDelivered && $isOrderActive;
            }

            if ($isAvailable) {
                $totalAvailable += $amount;
            } else {
                $totalPending += $amount;
            }
        }

        return [
            'total_pending' => round($totalPending, 2),
            'total_available' => round($totalAvailable, 2),
            'total_paid' => round($totalPaid, 2),
            'contracts_count' => $contractsCount,
            'orders_count' => $ordersCount,
            'total_referral' => round($totalReferral, 2),
            'referral_count' => $referralCount,
            'total_agent' => round($totalAgent, 2),
            'agent_count' => $agentCount,
            'total_curator' => round($totalCurator, 2),
            'curator_count' => $curatorCount,
        ];
    }
}
