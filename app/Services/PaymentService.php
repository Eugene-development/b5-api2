<?php

namespace App\Services;

use App\Models\Bonus;
use App\Models\AgentPayment;
use App\Models\BonusStatus;
use App\Models\PaymentStatus;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для управления выплатами.
 *
 * Управляет созданием выплат и обновлением статусов бонусов:
 * - Создание выплаты с группировкой бонусов
 * - Завершение выплаты (обновление статусов бонусов на "paid")
 * - Откат при ошибке выплаты
 */
class PaymentService
{
    /**
     * Рассчитать общую сумму выплаты.
     *
     * @param array $bonuses Массив Bonus или массив ID бонусов
     * @return float
     */
    public function calculatePaymentTotal(array $bonuses): float
    {
        $total = 0.0;

        foreach ($bonuses as $bonus) {
            if ($bonus instanceof Bonus) {
                $total += (float) $bonus->commission_amount;
            } elseif (is_numeric($bonus)) {
                $bonusModel = Bonus::find($bonus);
                if ($bonusModel) {
                    $total += (float) $bonusModel->commission_amount;
                }
            }
        }

        return round($total, 2);
    }

    /**
     * Создать выплату пользователю.
     *
     * @param int $userId ID пользователя
     * @param array $bonusIds Массив ID бонусов для включения в выплату
     * @param int $methodId ID способа выплаты
     * @param string|null $referenceNumber Номер платёжного документа
     * @return AgentPayment
     * @throws \InvalidArgumentException
     */
    public function createPayment(
        int $userId,
        array $bonusIds,
        int $methodId,
        ?string $referenceNumber = null
    ): AgentPayment {
        if (empty($bonusIds)) {
            throw new \InvalidArgumentException('Выберите хотя бы один бонус для выплаты');
        }


        // Проверяем, что все бонусы принадлежат пользователю и доступны к выплате
        $bonuses = Bonus::whereIn('id', $bonusIds)
            ->where('user_id', $userId)
            ->get();

        if ($bonuses->count() !== count($bonusIds)) {
            throw new \InvalidArgumentException('Некоторые бонусы не найдены или не принадлежат пользователю');
        }

        $pendingStatusId = BonusStatus::pendingId();
        foreach ($bonuses as $bonus) {
            if ($bonus->status_id !== $pendingStatusId) {
                throw new \InvalidArgumentException(
                    "Бонус #{$bonus->id} не в статусе 'Ожидание'"
                );
            }
        }

        // Рассчитываем общую сумму
        $totalAmount = $this->calculatePaymentTotal($bonuses->all());

        return DB::transaction(function () use ($userId, $bonuses, $methodId, $referenceNumber, $totalAmount) {
            // Создаём выплату
            $payment = AgentPayment::create([
                'agent_id' => $userId,
                'total_amount' => $totalAmount,
                'payment_date' => now(),
                'reference_number' => $referenceNumber,
                'status_id' => PaymentStatus::pendingId(),
                'method_id' => $methodId,
            ]);

            // Связываем бонусы с выплатой
            $payment->bonuses()->attach($bonuses->pluck('id'));

            return $payment;
        });
    }

    /**
     * Завершить выплату (статус = completed).
     * Обновляет статусы всех связанных бонусов на "paid".
     *
     * @param AgentPayment $payment
     * @return AgentPayment
     */
    public function completePayment(AgentPayment $payment): AgentPayment
    {
        return DB::transaction(function () use ($payment) {
            // Обновляем статус выплаты
            $payment->status_id = PaymentStatus::completedId();
            $payment->save();

            // Обновляем статусы всех связанных бонусов
            $paidStatusId = BonusStatus::paidId();
            foreach ($payment->bonuses as $bonus) {
                $bonus->status_id = $paidStatusId;
                $bonus->paid_at = now();
                $bonus->save();
            }

            return $payment;
        });
    }


    /**
     * Отметить выплату как неудачную (статус = failed).
     * Откатывает статусы всех связанных бонусов на "pending".
     *
     * @param AgentPayment $payment
     * @return AgentPayment
     */
    public function failPayment(AgentPayment $payment): AgentPayment
    {
        return DB::transaction(function () use ($payment) {
            // Обновляем статус выплаты
            $payment->status_id = PaymentStatus::failedId();
            $payment->save();

            // Откатываем статусы всех связанных бонусов
            $pendingStatusId = BonusStatus::pendingId();
            foreach ($payment->bonuses as $bonus) {
                $bonus->status_id = $pendingStatusId;
                $bonus->paid_at = null;
                $bonus->save();
            }

            return $payment;
        });
    }

    /**
     * Получить доступные к выплате бонусы пользователя.
     *
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAvailableBonusesForAgent(int $userId)
    {
        return Bonus::where('user_id', $userId)
            ->where('status_id', BonusStatus::pendingId())
            ->with(['contract', 'order', 'status'])
            ->orderBy('accrued_at', 'desc')
            ->get();
    }
}
