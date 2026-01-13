<?php

namespace App\Services;

use App\Models\Bonus;
use App\Models\BonusPaymentRequest;
use App\Models\BonusPaymentRequestBonus;
use App\Models\BonusStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для управления связью заявок на выплату с бонусами.
 *
 * Реализует:
 * - Связывание бонусов с заявкой по FIFO (по дате начисления)
 * - Автоматическое погашение бонусов при выплате
 * - Откат погашения при смене статуса
 *
 * Feature: bonus-payments
 * Requirements: 8.3, 8.4, 8.5, 9.1, 9.2, 9.3, 9.4, 10.1, 10.2, 10.3, 10.4, 10.5, 11.1, 11.3
 */
class BonusPaymentService
{
    /**
     * Получить доступные бонусы пользователя для выплаты.
     *
     * Возвращает бонусы, отсортированные по дате начисления (FIFO).
     * Бонус доступен если:
     * - paid_at IS NULL (не выплачен)
     * - Для договоров: договор выполнен И оплачен партнёром
     * - Для заказов: заказ доставлен
     *
     * @param int $userId
     * @return Collection<Bonus>
     */
    public function getAvailableBonuses(int $userId): Collection
    {
        return Bonus::where('user_id', $userId)
            ->whereNull('paid_at')
            ->where('commission_amount', '>', 0)
            ->with(['contract.status', 'contract.partnerPaymentStatus', 'order.status'])
            ->orderBy('accrued_at', 'asc')
            ->get()
            ->filter(function (Bonus $bonus) {
                return $this->isBonusAvailableForPayment($bonus);
            })
            ->values();
    }

    /**
     * Проверить, доступен ли бонус для выплаты.
     *
     * @param Bonus $bonus
     * @return bool
     */
    public function isBonusAvailableForPayment(Bonus $bonus): bool
    {
        if ($bonus->paid_at !== null) {
            return false;
        }

        if ($bonus->contract_id && $bonus->contract) {
            $contract = $bonus->contract;
            $isContractCompleted = $contract->status && $contract->status->slug === 'completed';
            $isPartnerPaid = $contract->partnerPaymentStatus && $contract->partnerPaymentStatus->code === 'paid';
            $isContractActive = $contract->is_active === true;

            return $isContractCompleted && $isPartnerPaid && $isContractActive;
        }

        if ($bonus->order_id && $bonus->order) {
            $order = $bonus->order;
            $isOrderDelivered = $order->status && $order->status->slug === 'delivered';
            $isOrderActive = $order->is_active === true;

            return $isOrderDelivered && $isOrderActive;
        }

        return false;
    }

    /**
     * Рассчитать общую сумму доступных бонусов пользователя.
     *
     * @param int $userId
     * @return float
     */
    public function calculateAvailableBalance(int $userId): float
    {
        $availableBonuses = $this->getAvailableBonuses($userId);

        return $availableBonuses->sum(function (Bonus $bonus) {
            return (float) $bonus->commission_amount;
        });
    }

    /**
     * Связать доступные бонусы с заявкой на выплату.
     *
     * Использует алгоритм FIFO по дате начисления (accrued_at).
     * Каждый бонус покрывается полностью или частично.
     *
     * @param BonusPaymentRequest $request
     * @param int $userId
     * @param float $amount
     * @return array Массив связанных бонусов с информацией о покрытии
     */
    public function linkBonusesToPaymentRequest(
        BonusPaymentRequest $request,
        int $userId,
        float $amount
    ): array {
        $availableBonuses = $this->getAvailableBonuses($userId);
        $remainingAmount = $amount;
        $linkedBonuses = [];

        foreach ($availableBonuses as $bonus) {
            if ($remainingAmount <= 0) {
                break;
            }

            $bonusAmount = (float) $bonus->commission_amount;
            $coveredAmount = min($bonusAmount, $remainingAmount);

            // Создаём связь
            BonusPaymentRequestBonus::create([
                'payment_request_id' => $request->id,
                'bonus_id' => $bonus->id,
                'covered_amount' => $coveredAmount,
            ]);

            $linkedBonuses[] = [
                'bonus' => $bonus,
                'covered_amount' => $coveredAmount,
                'is_fully_covered' => $coveredAmount >= $bonusAmount,
            ];

            $remainingAmount -= $coveredAmount;
        }

        return $linkedBonuses;
    }


    /**
     * Погасить бонусы при выплате заявки.
     *
     * Вызывается при переходе статуса заявки в "paid".
     * - Полностью покрытые бонусы: статус = paid, paid_at = now
     * - Частично покрытые бонусы: разделяются на два
     *
     * @param BonusPaymentRequest $request
     * @return void
     */
    public function settleBonuses(BonusPaymentRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $linkedBonuses = $request->linkedBonuses()->with('bonus')->get();
            $paidStatusId = BonusStatus::paidId();
            $pendingStatusId = BonusStatus::pendingId();
            $now = now();

            foreach ($linkedBonuses as $link) {
                $bonus = $link->bonus;
                $coveredAmount = (float) $link->covered_amount;
                $bonusAmount = (float) $bonus->commission_amount;

                if ($coveredAmount >= $bonusAmount) {
                    // Полное покрытие — просто обновляем статус
                    $bonus->update([
                        'status_id' => $paidStatusId,
                        'paid_at' => $now,
                    ]);
                } else {
                    // Частичное покрытие — разделяем бонус
                    $remainingAmount = $bonusAmount - $coveredAmount;

                    // Создаём новый бонус с остатком
                    Bonus::create([
                        'user_id' => $bonus->user_id,
                        'contract_id' => $bonus->contract_id,
                        'order_id' => $bonus->order_id,
                        'commission_amount' => $remainingAmount,
                        'percentage' => $bonus->percentage,
                        'status_id' => $pendingStatusId,
                        'recipient_type' => $bonus->recipient_type,
                        'bonus_type' => $bonus->bonus_type,
                        'referral_user_id' => $bonus->referral_user_id,
                        'accrued_at' => $bonus->accrued_at,
                        'available_at' => $bonus->available_at,
                        'paid_at' => null,
                    ]);

                    // Обновляем оригинальный бонус
                    $bonus->update([
                        'commission_amount' => $coveredAmount,
                        'status_id' => $paidStatusId,
                        'paid_at' => $now,
                    ]);
                }
            }
        });
    }


    /**
     * Откатить погашение бонусов.
     *
     * Вызывается при откате статуса заявки из "paid".
     * - Восстанавливает статус бонусов в pending
     * - Объединяет разделённые бонусы обратно
     *
     * @param BonusPaymentRequest $request
     * @return void
     */
    public function rollbackSettlement(BonusPaymentRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $linkedBonuses = $request->linkedBonuses()->with('bonus')->get();
            $pendingStatusId = BonusStatus::pendingId();

            foreach ($linkedBonuses as $link) {
                $bonus = $link->bonus;
                $coveredAmount = (float) $link->covered_amount;

                // Ищем "остаточный" бонус, созданный при частичном покрытии
                // Он имеет те же contract_id/order_id, тот же user_id, ту же дату начисления,
                // но paid_at = NULL и был создан после оригинального бонуса
                $remainderBonus = $this->findRemainderBonus($bonus);

                if ($remainderBonus) {
                    // Объединяем обратно
                    $originalAmount = $coveredAmount + (float) $remainderBonus->commission_amount;
                    $bonus->update([
                        'commission_amount' => $originalAmount,
                        'status_id' => $pendingStatusId,
                        'paid_at' => null,
                    ]);

                    // Удаляем остаточный бонус
                    $remainderBonus->delete();
                } else {
                    // Просто возвращаем статус
                    $bonus->update([
                        'status_id' => $pendingStatusId,
                        'paid_at' => null,
                    ]);
                }
            }
        });
    }

    /**
     * Найти остаточный бонус, созданный при частичном покрытии.
     *
     * @param Bonus $originalBonus
     * @return Bonus|null
     */
    private function findRemainderBonus(Bonus $originalBonus): ?Bonus
    {
        $query = Bonus::where('user_id', $originalBonus->user_id)
            ->whereNull('paid_at')
            ->where('id', '!=', $originalBonus->id);

        // Ищем по тем же критериям источника
        if ($originalBonus->contract_id) {
            $query->where('contract_id', $originalBonus->contract_id);
        } else {
            $query->whereNull('contract_id');
        }

        if ($originalBonus->order_id) {
            $query->where('order_id', $originalBonus->order_id);
        } else {
            $query->whereNull('order_id');
        }

        // Та же дата начисления
        if ($originalBonus->accrued_at) {
            $query->whereDate('accrued_at', $originalBonus->accrued_at->toDateString());
        }

        // Тот же тип бонуса
        if ($originalBonus->bonus_type) {
            $query->where('bonus_type', $originalBonus->bonus_type);
        }

        // Тот же реферал (если есть)
        if ($originalBonus->referral_user_id) {
            $query->where('referral_user_id', $originalBonus->referral_user_id);
        } else {
            $query->whereNull('referral_user_id');
        }

        // Берём бонус, созданный позже оригинального
        return $query->where('created_at', '>', $originalBonus->created_at)
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * Проверить, была ли заявка уже выплачена (бонусы погашены).
     *
     * @param BonusPaymentRequest $request
     * @return bool
     */
    public function isSettled(BonusPaymentRequest $request): bool
    {
        $linkedBonuses = $request->linkedBonuses()->with('bonus')->get();

        if ($linkedBonuses->isEmpty()) {
            return false;
        }

        // Если хотя бы один связанный бонус имеет paid_at, считаем что заявка была выплачена
        return $linkedBonuses->some(function ($link) {
            return $link->bonus && $link->bonus->paid_at !== null;
        });
    }
}
