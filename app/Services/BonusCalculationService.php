<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Order;
use App\Models\Project;

/**
 * Сервис для централизованного расчёта бонусов агентов и кураторов.
 *
 * Бонусы рассчитываются как процент от суммы договора/закупки:
 * - Для договоров: дефолт агент 3%, куратор 2%
 * - Для закупок: дефолт агент 5%, куратор 5%
 *
 * Бонус начисляется только когда:
 * 1. Указана сумма (amount > 0)
 * 2. Сущность активна (is_active = true)
 */
class BonusCalculationService
{
    /**
     * Рассчитать сумму бонуса.
     *
     * @param float|null $amount Сумма договора/закупки
     * @param float $percentage Процент (0-100)
     * @param bool $isActive Активна ли сущность
     * @return float Рассчитанный бонус (0 если условия не выполнены)
     */
    public function calculateBonus(?float $amount, float $percentage, bool $isActive): float
    {
        // Бонус = 0 если сущность неактивна или сумма не указана/нулевая
        if (!$isActive || $amount === null || $amount <= 0) {
            return 0.0;
        }

        // Валидация процента
        if ($percentage < 0 || $percentage > 100) {
            return 0.0;
        }

        // Расчёт: сумма × процент / 100
        return round($amount * $percentage / 100, 2);
    }

    /**
     * Пересчитать бонусы для договора.
     * Вызывается автоматически при сохранении договора через Model Events.
     *
     * @param Contract $contract
     * @return void
     */
    public function recalculateContractBonuses(Contract $contract): void
    {
        $contract->agent_bonus = $this->calculateBonus(
            $contract->contract_amount,
            $contract->agent_percentage ?? 3.0,
            $contract->is_active ?? true
        );

        $contract->curator_bonus = $this->calculateBonus(
            $contract->contract_amount,
            $contract->curator_percentage ?? 2.0,
            $contract->is_active ?? true
        );
    }

    /**
     * Пересчитать бонусы для закупки.
     * Вызывается автоматически при сохранении закупки через Model Events.
     *
     * @param Order $order
     * @return void
     */
    public function recalculateOrderBonuses(Order $order): void
    {
        $order->agent_bonus = $this->calculateBonus(
            $order->order_amount,
            $order->agent_percentage ?? 5.0,
            $order->is_active ?? true
        );

        $order->curator_bonus = $this->calculateBonus(
            $order->order_amount,
            $order->curator_percentage ?? 5.0,
            $order->is_active ?? true
        );
    }

    /**
     * Получить сводку бонусов по проекту.
     * Агрегирует бонусы из всех договоров и закупок проекта.
     *
     * @param string $projectId
     * @return array{
     *   contracts: array,
     *   orders: array,
     *   totalAgentBonus: float,
     *   totalCuratorBonus: float
     * }
     */
    public function getProjectBonusSummary(string $projectId): array
    {
        // Получаем все договоры проекта
        $contracts = Contract::where('project_id', $projectId)->get();

        // Получаем все закупки проекта
        $orders = Order::where('project_id', $projectId)->get();

        // Агрегируем бонусы
        $totalAgentBonus = 0.0;
        $totalCuratorBonus = 0.0;

        $contractsData = [];
        foreach ($contracts as $contract) {
            $contractsData[] = [
                'id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'contract_amount' => $contract->contract_amount,
                'agent_percentage' => $contract->agent_percentage,
                'curator_percentage' => $contract->curator_percentage,
                'agent_bonus' => $contract->agent_bonus ?? 0,
                'curator_bonus' => $contract->curator_bonus ?? 0,
                'is_active' => $contract->is_active,
            ];

            $totalAgentBonus += $contract->agent_bonus ?? 0;
            $totalCuratorBonus += $contract->curator_bonus ?? 0;
        }

        $ordersData = [];
        foreach ($orders as $order) {
            $ordersData[] = [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'order_amount' => $order->order_amount,
                'agent_percentage' => $order->agent_percentage ?? 5.0,
                'curator_percentage' => $order->curator_percentage ?? 5.0,
                'agent_bonus' => $order->agent_bonus ?? 0,
                'curator_bonus' => $order->curator_bonus ?? 0,
                'is_active' => $order->is_active,
            ];

            $totalAgentBonus += $order->agent_bonus ?? 0;
            $totalCuratorBonus += $order->curator_bonus ?? 0;
        }

        return [
            'contracts' => $contractsData,
            'orders' => $ordersData,
            'totalAgentBonus' => round($totalAgentBonus, 2),
            'totalCuratorBonus' => round($totalCuratorBonus, 2),
        ];
    }

    /**
     * Получить общий бонус агента по проекту.
     *
     * @param string $projectId
     * @return float
     */
    public function getTotalAgentBonus(string $projectId): float
    {
        $summary = $this->getProjectBonusSummary($projectId);
        return $summary['totalAgentBonus'];
    }

    /**
     * Получить общий бонус куратора по проекту.
     *
     * @param string $projectId
     * @return float
     */
    public function getTotalCuratorBonus(string $projectId): float
    {
        $summary = $this->getProjectBonusSummary($projectId);
        return $summary['totalCuratorBonus'];
    }
}
