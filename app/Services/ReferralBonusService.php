<?php

namespace App\Services;

use App\Models\AgentBonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для управления реферальными бонусами.
 *
 * Отвечает за:
 * - Расчёт реферальной комиссии (0.5% от суммы сделки)
 * - Создание реферальных бонусов при сделках рефералов
 * - Проверку срока действия реферальной программы
 * - Получение статистики по реферальным бонусам
 */
class ReferralBonusService
{
    /**
     * Процент реферальной комиссии.
     */
    const REFERRAL_COMMISSION_PERCENTAGE = 0.5;

    /**
     * Срок действия реферальной программы в годах.
     */
    const REFERRAL_PROGRAM_YEARS = 2;

    /**
     * Рассчитать сумму реферальной комиссии.
     *
     * @param float $amount Сумма сделки
     * @return float Сумма комиссии (0.5% от суммы)
     */
    public function calculateReferralCommission(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }
        return round($amount * self::REFERRAL_COMMISSION_PERCENTAGE / 100, 2);
    }

    /**
     * Создать реферальный бонус для договора.
     *
     * @param Contract $contract
     * @param int $agentId ID агента (реферала), совершившего сделку
     * @return AgentBonus|null
     */
    public function createReferralBonusForContract(Contract $contract, int $agentId): ?AgentBonus
    {
        // Получаем реферера агента
        $referrerId = $this->getReferrerId($agentId);
        if (!$referrerId) {
            return null;
        }

        // Проверяем срок действия реферальной программы
        if (!$this->isReferralProgramActive($agentId)) {
            Log::info('ReferralBonusService: Referral program expired', [
                'agent_id' => $agentId,
                'referrer_id' => $referrerId,
                'contract_id' => $contract->id
            ]);
            return null;
        }

        // Проверяем условия создания бонуса
        if (!$contract->is_active || $contract->contract_amount === null) {
            return null;
        }

        $commissionAmount = $this->calculateReferralCommission((float) $contract->contract_amount);

        Log::info('ReferralBonusService: Creating referral bonus for contract', [
            'referrer_id' => $referrerId,
            'agent_id' => $agentId,
            'contract_id' => $contract->id,
            'contract_amount' => $contract->contract_amount,
            'commission_amount' => $commissionAmount
        ]);

        return AgentBonus::create([
            'agent_id' => $referrerId,
            'contract_id' => $contract->id,
            'order_id' => null,
            'commission_amount' => $commissionAmount,
            'status_id' => BonusStatus::pendingId(),
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'bonus_type' => 'referral',
            'referral_user_id' => $agentId,
        ]);
    }

    /**
     * Создать реферальный бонус для заказа.
     *
     * @param Order $order
     * @param int $agentId ID агента (реферала), совершившего сделку
     * @return AgentBonus|null
     */
    public function createReferralBonusForOrder(Order $order, int $agentId): ?AgentBonus
    {
        // Получаем реферера агента
        $referrerId = $this->getReferrerId($agentId);
        if (!$referrerId) {
            return null;
        }

        // Проверяем срок действия реферальной программы
        if (!$this->isReferralProgramActive($agentId)) {
            Log::info('ReferralBonusService: Referral program expired', [
                'agent_id' => $agentId,
                'referrer_id' => $referrerId,
                'order_id' => $order->id
            ]);
            return null;
        }

        // Проверяем условия создания бонуса
        if (!$order->is_active || !$order->order_amount || $order->order_amount <= 0) {
            return null;
        }

        $commissionAmount = $this->calculateReferralCommission((float) $order->order_amount);

        Log::info('ReferralBonusService: Creating referral bonus for order', [
            'referrer_id' => $referrerId,
            'agent_id' => $agentId,
            'order_id' => $order->id,
            'order_amount' => $order->order_amount,
            'commission_amount' => $commissionAmount
        ]);

        return AgentBonus::create([
            'agent_id' => $referrerId,
            'contract_id' => null,
            'order_id' => $order->id,
            'commission_amount' => $commissionAmount,
            'status_id' => BonusStatus::pendingId(),
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'bonus_type' => 'referral',
            'referral_user_id' => $agentId,
        ]);
    }

    /**
     * Получить ID реферера для пользователя.
     *
     * @param int $userId ID пользователя
     * @return int|null ID реферера или null
     */
    public function getReferrerId(int $userId): ?int
    {
        $user = User::find($userId);
        return $user?->user_id;
    }

    /**
     * Проверить, не истёк ли срок реферальной программы для реферала.
     *
     * @param int $referralId ID реферала
     * @return bool true если срок не истёк
     */
    public function isReferralProgramActive(int $referralId): bool
    {
        $referral = User::find($referralId);
        if (!$referral) {
            return false;
        }

        $registrationDate = Carbon::parse($referral->created_at);
        $expirationDate = $registrationDate->copy()->addYears(self::REFERRAL_PROGRAM_YEARS);

        return Carbon::now()->lt($expirationDate);
    }

    /**
     * Получить статистику реферальных бонусов для реферера.
     *
     * @param int $referrerId ID реферера
     * @return array
     */
    public function getReferralStats(int $referrerId): array
    {
        $bonuses = AgentBonus::where('agent_id', $referrerId)
            ->where('bonus_type', 'referral')
            ->get();

        $totalPending = 0.0;
        $totalAvailable = 0.0;
        $totalPaid = 0.0;

        foreach ($bonuses as $bonus) {
            $amount = (float) $bonus->commission_amount;

            if ($bonus->available_at === null && $bonus->paid_at === null) {
                $totalPending += $amount;
            } elseif ($bonus->available_at !== null && $bonus->paid_at === null) {
                $totalAvailable += $amount;
            } elseif ($bonus->paid_at !== null) {
                $totalPaid += $amount;
            }
        }

        // Получаем статистику по рефералам
        $referralStats = $this->getReferralUserStats($referrerId);

        return [
            'total_pending' => round($totalPending, 2),
            'total_available' => round($totalAvailable, 2),
            'total_paid' => round($totalPaid, 2),
            'total' => round($totalPending + $totalAvailable + $totalPaid, 2),
            'referrals' => $referralStats,
        ];
    }

    /**
     * Получить статистику по каждому рефералу.
     *
     * @param int $referrerId ID реферера
     * @return array
     */
    private function getReferralUserStats(int $referrerId): array
    {
        $referrals = User::where('user_id', $referrerId)->get();
        $stats = [];

        foreach ($referrals as $referral) {
            $bonusSum = AgentBonus::where('agent_id', $referrerId)
                ->where('bonus_type', 'referral')
                ->where('referral_user_id', $referral->id)
                ->sum('commission_amount');

            $isActive = $this->isReferralProgramActive($referral->id);

            $stats[] = [
                'user_id' => $referral->id,
                'name' => $referral->name,
                'email' => $referral->email,
                'registered_at' => $referral->created_at,
                'is_active' => $isActive,
                'total_bonus' => round((float) $bonusSum, 2),
            ];
        }

        return $stats;
    }
}
