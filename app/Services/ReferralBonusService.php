<?php

namespace App\Services;

use App\Models\Bonus;
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
     * @return Bonus|null
     */
    public function createReferralBonusForContract(Contract $contract, int $agentId): ?Bonus
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

        return Bonus::create([
            'user_id' => $referrerId,
            'contract_id' => $contract->id,
            'order_id' => null,
            'commission_amount' => $commissionAmount,
            'percentage' => self::REFERRAL_COMMISSION_PERCENTAGE,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => Bonus::RECIPIENT_REFERRER,
            'bonus_type' => 'referral',
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'referral_user_id' => $agentId,
        ]);
    }

    /**
     * Создать реферальный бонус для заказа.
     *
     * @param Order $order
     * @param int $agentId ID агента (реферала), совершившего сделку
     * @return Bonus|null
     */
    public function createReferralBonusForOrder(Order $order, int $agentId): ?Bonus
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

        return Bonus::create([
            'user_id' => $referrerId,
            'contract_id' => null,
            'order_id' => $order->id,
            'commission_amount' => $commissionAmount,
            'percentage' => self::REFERRAL_COMMISSION_PERCENTAGE,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => Bonus::RECIPIENT_REFERRER,
            'bonus_type' => 'referral',
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'referral_user_id' => $agentId,
        ]);
    }

    /**
     * Получить ID реферера для пользователя.
     *
     * Связь между реферером и рефералом осуществляется через:
     * - referrer_key у реферала (ссылается на key реферера)
     * - key у реферера (уникальный ключ пользователя)
     *
     * @param int $userId ID пользователя (реферала)
     * @return int|null ID реферера или null
     */
    public function getReferrerId(int $userId): ?int
    {
        $user = User::find($userId);
        if (!$user || !$user->referrer_key) {
            return null;
        }

        // Ищем реферера по его ключу (key), который совпадает с referrer_key реферала
        $referrer = User::where('key', $user->referrer_key)->first();
        return $referrer?->id;
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
        $bonuses = Bonus::where('user_id', $referrerId)
            ->where('recipient_type', Bonus::RECIPIENT_REFERRER)
            ->with(['contract.status', 'contract.partnerPaymentStatus', 'order.status'])
            ->get();

        $totalPending = 0.0;
        $totalAvailable = 0.0;
        $totalPaid = 0.0;

        foreach ($bonuses as $bonus) {
            $amount = (float) $bonus->commission_amount;

            // Выплачено: бонусы, которые уже выплачены
            if ($bonus->paid_at !== null) {
                $totalPaid += $amount;
                continue;
            }

            // Определяем доступность бонуса к выплате (как в BonusService::getAgentStats)
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
        // Получаем ключ реферера
        $referrer = User::find($referrerId);
        if (!$referrer || !$referrer->key) {
            return [];
        }

        // Ищем рефералов по referrer_key
        $referrals = User::where('referrer_key', $referrer->key)->get();
        $stats = [];

        foreach ($referrals as $referral) {
            $bonusSum = Bonus::where('user_id', $referrerId)
                ->where('recipient_type', Bonus::RECIPIENT_REFERRER)
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
