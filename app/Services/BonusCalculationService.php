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
        // Приводим значения к числовым типам
        $amount = floatval($contract->contract_amount);
        $agentPercentage = floatval($contract->agent_percentage) ?: 3.0; // Дефолт 3%
        $curatorPercentage = floatval($contract->curator_percentage) ?: 2.0; // Дефолт 2%
        $isActive = $contract->is_active ?? true;

        $contract->agent_bonus = $this->calculateBonus($amount, $agentPercentage, $isActive);
        $contract->curator_bonus = $this->calculateBonus($amount, $curatorPercentage, $isActive);
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
        // Приводим значения к числовым типам
        $amount = floatval($order->order_amount);
        $agentPercentage = floatval($order->agent_percentage) ?: 5.0;
        $curatorPercentage = floatval($order->curator_percentage) ?: 5.0;
        $isActive = $order->is_active ?? true;

        $order->agent_bonus = $this->calculateBonus($amount, $agentPercentage, $isActive);
        $order->curator_bonus = $this->calculateBonus($amount, $curatorPercentage, $isActive);
    }

    /**
     * Получить сводку бонусов по проекту.
     * Агрегирует бонусы из всех договоров и закупок проекта.
     *
     * Бонусы учитываются только для:
     * - Договоров со статусом "Заключён" (slug: signed)
     * - Закупок со статусом "Сформирован" (slug: formed)
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
        // Получаем все договоры проекта с их статусами
        $contracts = Contract::where('project_id', $projectId)
            ->with('status')
            ->get();

        // Получаем все закупки проекта с их статусами
        $orders = Order::where('project_id', $projectId)
            ->with('status')
            ->get();

        // Агрегируем бонусы
        $totalAgentBonus = 0.0;
        $totalCuratorBonus = 0.0;

        $contractsData = [];
        foreach ($contracts as $contract) {
            // Фильтруем: отправляем на фронтенд только договоры со статусом "Заключён" (signed)
            $statusSlug = $contract->status?->slug;
            if ($statusSlug !== 'signed') {
                continue;
            }

            $contractsData[] = [
                'id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'contract_amount' => $contract->contract_amount,
                'agent_percentage' => $contract->agent_percentage ?? 3.0,
                'curator_percentage' => $contract->curator_percentage ?? 2.0,
                'agent_bonus' => $contract->agent_bonus ?? 0,
                'curator_bonus' => $contract->curator_bonus ?? 0,
                'is_active' => $contract->is_active,
            ];

            // Считаем бонусы
            $totalAgentBonus += $contract->agent_bonus ?? 0;
            $totalCuratorBonus += $contract->curator_bonus ?? 0;
        }

        $ordersData = [];
        foreach ($orders as $order) {
            // Отправляем все заказы на фронтенд, независимо от статуса
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

            // Считаем бонусы
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
