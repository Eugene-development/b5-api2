<?php

namespace App\Services;

use App\Models\Bonus;

final class BonusStatisticsService
{
    public function query(array $filters = [])
    {
        $q = Bonus::with(['status', 'contract.status', 'contract.partnerPaymentStatus', 'contract.project.status', 'order.status', 'order.project.status']);
        if (($filters['requester_type'] ?? null) === 'agent') {
            $q->whereIn('recipient_type', ['agent', 'referrer']);
        }
        foreach (['recipient_type', 'bonus_type'] as $field) {
            if (! empty($filters[$field])) {
                $q->where($field, $filters[$field]);
            }
        }
        if ($id = $filters['user_id'] ?? $filters['agent_id'] ?? null) {
            $q->where('user_id', $id);
        }
        if (! empty($filters['status_code'])) {
            $q->whereHas('status', fn ($s) => $s->where('code', $filters['status_code']));
        }
        if (! empty($filters['date_from'])) {
            $q->where('accrued_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->where('accrued_at', '<=', $filters['date_to']);
        }
        if (in_array($filters['source_type'] ?? null, ['contract', 'order'], true)) {
            $q->whereNotNull($filters['source_type'].'_id');
        }

        return $q;
    }

    public function calculate(array $filters = []): array
    {
        $totals = ['total_pending' => 0, 'total_available' => 0, 'total_requested' => 0, 'total_paid' => 0,
            'total_agent' => 0, 'total_curator' => 0, 'total_referral' => 0, 'agent_count' => 0, 'curator_count' => 0, 'referral_count' => 0,
            'contracts_count' => 0, 'orders_count' => 0];
        foreach ($this->query($filters)->get() as $bonus) {
            $amount = FinancialLedger::cents($bonus->commission_amount);
            $type = $bonus->recipient_type === 'referrer' ? 'referral' : $bonus->recipient_type;
            $totals['total_'.$type] += $amount;
            $totals[$type.'_count']++;
            $totals[$bonus->contract_id ? 'contracts_count' : 'orders_count']++;
            if ($bonus->paid_at !== null) {
                $totals['total_paid'] += $amount;
            } elseif (FinancialLedger::reserved($bonus)) {
                $totals['total_requested'] += $amount;
            } elseif (app(BonusPaymentService::class)->isBonusAvailableForPayment($bonus)) {
                $totals['total_available'] += $amount;
            } elseif ($bonus->status?->code !== 'cancelled') {
                $totals['total_pending'] += $amount;
            }
        }
        foreach ($totals as $key => $value) {
            if (str_starts_with($key, 'total_')) {
                $totals[$key] = $value / 100;
            }
        }

        return $totals;
    }
}
