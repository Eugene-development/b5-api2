<?php

namespace App\Services;

use App\Models\AgentBonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use App\Models\PartnerPaymentStatus;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для управления бонусами агентов.
 *
 * Управляет жизненным циклом бонусов:
 * - Создание бонуса при создании договора/закупки
 * - Пересчёт при изменении суммы/процента
 * - Переход в статус "доступно к выплате" при оплате партнёром
 * - Откат статуса при отмене оплаты
 */
class BonusService
{
    /**
     * Рассчитать сумму комиссии.
     *
     * @param float $amount Сумма договора/закупки
     * @param float $percentage Процент агента (0-100)
     * @return float Сумма комиссии
     */
    public function calculateCommission(float $amount, float $percentage): float
    {
        if ($amount <= 0 || $percentage < 0 || $percentage > 100) {
            return 0.0;
        }
        return round($amount * $percentage / 100, 2);
    }

    /**
     * Создать бонус для договора.
     *
     * @param Contract $contract
     * @return AgentBonus|null
     */
    public function createBonusForContract(Contract $contract): ?AgentBonus
    {
        // Проверяем условия создания бонуса
        if (!$contract->is_active || !$contract->contract_amount || $contract->contract_amount <= 0) {
            return null;
        }

        // Получаем agent_id из проекта
        $agentId = $this->getAgentIdFromProject($contract->project_id);
        if (!$agentId) {
            return null;
        }


        $commissionAmount = $this->calculateCommission(
            (float) $contract->contract_amount,
            (float) $contract->agent_percentage
        );

        return AgentBonus::create([
            'agent_id' => $agentId,
            'contract_id' => $contract->id,
            'order_id' => null,
            'commission_amount' => $commissionAmount,
            'status_id' => BonusStatus::accruedId(),
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * Создать бонус для закупки.
     *
     * @param Order $order
     * @return AgentBonus|null
     */
    public function createBonusForOrder(Order $order): ?AgentBonus
    {
        // Проверяем условия создания бонуса
        if (!$order->is_active || !$order->order_amount || $order->order_amount <= 0) {
            return null;
        }

        // Получаем agent_id из проекта
        $agentId = $this->getAgentIdFromProject($order->project_id);
        if (!$agentId) {
            return null;
        }

        $commissionAmount = $this->calculateCommission(
            (float) $order->order_amount,
            (float) $order->agent_percentage
        );

        return AgentBonus::create([
            'agent_id' => $agentId,
            'contract_id' => null,
            'order_id' => $order->id,
            'commission_amount' => $commissionAmount,
            'status_id' => BonusStatus::accruedId(),
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * Пересчитать бонус при изменении суммы или процента.
     *
     * @param AgentBonus $bonus
     * @return AgentBonus
     */
    public function recalculateBonus(AgentBonus $bonus): AgentBonus
    {
        $amount = 0.0;
        $percentage = 0.0;
        $isActive = true;

        if ($bonus->contract_id && $bonus->contract) {
            $amount = (float) $bonus->contract->contract_amount;
            $percentage = (float) $bonus->contract->agent_percentage;
            $isActive = $bonus->contract->is_active;
        } elseif ($bonus->order_id && $bonus->order) {
            $amount = (float) $bonus->order->order_amount;
            $percentage = (float) $bonus->order->agent_percentage;
            $isActive = $bonus->order->is_active;
        }

        // Если сущность деактивирована, обнуляем комиссию
        if (!$isActive) {
            $bonus->commission_amount = 0;
        } else {
            $bonus->commission_amount = $this->calculateCommission($amount, $percentage);
        }

        $bonus->save();
        return $bonus;
    }


    /**
     * Перевести бонус в статус "Доступно к выплате".
     *
     * @param AgentBonus $bonus
     * @return AgentBonus
     */
    public function markBonusAsAvailable(AgentBonus $bonus): AgentBonus
    {
        $bonus->status_id = BonusStatus::availableForPaymentId();
        $bonus->available_at = now();
        $bonus->save();
        return $bonus;
    }

    /**
     * Откатить бонус в статус "Начислено".
     *
     * @param AgentBonus $bonus
     * @return AgentBonus
     */
    public function revertBonusToAccrued(AgentBonus $bonus): AgentBonus
    {
        $bonus->status_id = BonusStatus::accruedId();
        $bonus->available_at = null;
        $bonus->save();
        return $bonus;
    }

    /**
     * Получить статистику бонусов агента.
     *
     * @param int $agentId
     * @param array|null $filters
     * @return array
     */
    public function getAgentStats(int $agentId, ?array $filters = null): array
    {
        $query = AgentBonus::where('agent_id', $agentId);

        // Применяем фильтры если указаны
        if ($filters) {
            if (!empty($filters['date_from'])) {
                $query->where('accrued_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $query->where('accrued_at', '<=', $filters['date_to']);
            }
            if (!empty($filters['source_type'])) {
                if ($filters['source_type'] === 'contract') {
                    $query->whereNotNull('contract_id');
                } elseif ($filters['source_type'] === 'order') {
                    $query->whereNotNull('order_id');
                }
            }
        }

        $bonuses = $query->get();

        $totalAccrued = 0.0;
        $totalAvailable = 0.0;
        $totalPaid = 0.0;

        foreach ($bonuses as $bonus) {
            $amount = (float) $bonus->commission_amount;
            $statusCode = $bonus->status->code ?? '';

            // Все бонусы входят в "начислено"
            $totalAccrued += $amount;

            if ($statusCode === 'available_for_payment') {
                $totalAvailable += $amount;
            } elseif ($statusCode === 'paid') {
                $totalPaid += $amount;
            }
        }

        return [
            'total_accrued' => round($totalAccrued, 2),
            'total_available' => round($totalAvailable, 2),
            'total_paid' => round($totalPaid, 2),
        ];
    }


    /**
     * Обработать изменение статуса оплаты партнёром для договора.
     *
     * @param Contract $contract
     * @param string $newStatusCode
     * @return void
     */
    public function handleContractPartnerPaymentStatusChange(Contract $contract, string $newStatusCode): void
    {
        $bonus = $contract->agentBonus;
        if (!$bonus) {
            return;
        }

        if ($newStatusCode === 'paid') {
            $this->markBonusAsAvailable($bonus);
        } elseif ($newStatusCode === 'pending') {
            $this->revertBonusToAccrued($bonus);
        }
    }

    /**
     * Обработать изменение статуса оплаты партнёром для закупки.
     *
     * @param Order $order
     * @param string $newStatusCode
     * @return void
     */
    public function handleOrderPartnerPaymentStatusChange(Order $order, string $newStatusCode): void
    {
        $bonus = $order->agentBonus;
        if (!$bonus) {
            return;
        }

        if ($newStatusCode === 'paid') {
            $this->markBonusAsAvailable($bonus);
        } elseif ($newStatusCode === 'pending') {
            $this->revertBonusToAccrued($bonus);
        }
    }

    /**
     * Получить ID агента из проекта.
     *
     * @param string $projectId
     * @return int|null
     */
    private function getAgentIdFromProject(string $projectId): ?int
    {
        // Ищем агента в связи project_user
        $projectUser = DB::table('project_user')
            ->where('project_id', $projectId)
            ->first();

        if ($projectUser) {
            return $projectUser->user_id;
        }

        // Альтернативно: ищем в таблице projects поле agent_id если оно есть
        $project = DB::table('projects')
            ->where('id', $projectId)
            ->first();

        if ($project && isset($project->agent_id)) {
            return $project->agent_id;
        }

        return null;
    }
}
