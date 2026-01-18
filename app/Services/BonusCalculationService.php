<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Order;
use App\Models\Project;
use App\Models\AgentBonus;

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
     * - Договоров со статусом "Заключён" (slug: signed) или "Выполнен" (slug: completed)
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
        // Получаем все договоры проекта с их статусами и бонусами
        $contracts = Contract::where('project_id', $projectId)
            ->with(['status', 'agentBonus', 'curatorBonus', 'partnerPaymentStatus'])
            ->get();

        // Получаем все закупки проекта с их статусами и бонусами
        $orders = Order::where('project_id', $projectId)
            ->with(['status', 'agentBonus', 'curatorBonus'])
            ->get();

        // Агрегируем бонусы
        $totalAgentBonus = 0.0;
        $totalCuratorBonus = 0.0;

        // Статусы договоров, которые учитываются в бонусах
        $allowedContractStatuses = ['signed', 'completed'];

        $contractsData = [];
        foreach ($contracts as $contract) {
            // Фильтруем: пропускаем неактивные договоры — они не должны отображаться в ЛК агента
            if ($contract->is_active !== true) {
                continue;
            }

            // Фильтруем: отправляем на фронтенд только договоры со статусом "Заключён" или "Выполнен"
            $statusSlug = $contract->status?->slug;
            if (!in_array($statusSlug, $allowedContractStatuses)) {
                continue;
            }

            // Получаем бонусы из таблицы bonuses
            $agentBonus = $contract->agentBonus;
            $curatorBonus = $contract->curatorBonus;

            // Суммы бонусов из связанных записей в таблице bonuses
            $agentBonusAmount = $agentBonus ? (float)$agentBonus->commission_amount : 0.0;
            $curatorBonusAmount = $curatorBonus ? (float)$curatorBonus->commission_amount : 0.0;

            // Проверяем доступность бонуса к выплате
            // Для договоров: оба условия (is_contract_completed И is_partner_paid) должны быть true
            // И бонус ещё не выплачен (paid_at === null)
            $isContractCompleted = $contract->status && $contract->status->slug === 'completed';
            $isPartnerPaid = $contract->partnerPaymentStatus && $contract->partnerPaymentStatus->code === 'paid';
            $isNotPaid = $agentBonus && $agentBonus->paid_at === null;

            $isAvailable = $isContractCompleted && $isPartnerPaid && $isNotPaid;

            $contractsData[] = [
                'id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'contract_amount' => $contract->contract_amount,
                'agent_percentage' => $contract->agent_percentage ?? 3.0,
                'curator_percentage' => $contract->curator_percentage ?? 2.0,
                'agent_bonus' => $agentBonusAmount,
                'curator_bonus' => $curatorBonusAmount,
                'is_active' => $contract->is_active,
                'is_available' => $isAvailable,
                'is_paid' => $agentBonus && $agentBonus->paid_at !== null,
            ];

            // Считаем бонусы
            $totalAgentBonus += $agentBonusAmount;
            $totalCuratorBonus += $curatorBonusAmount;
        }

        $ordersData = [];
        foreach ($orders as $order) {
            // Фильтруем: пропускаем неактивные заказы — они не должны отображаться в ЛК агента
            if ($order->is_active !== true) {
                continue;
            }

            // Получаем бонусы из таблицы bonuses
            $agentBonus = $order->agentBonus;
            $curatorBonus = $order->curatorBonus;

            // Суммы бонусов из связанных записей в таблице bonuses
            $agentBonusAmount = $agentBonus ? (float)$agentBonus->commission_amount : 0.0;
            $curatorBonusAmount = $curatorBonus ? (float)$curatorBonus->commission_amount : 0.0;

            // Проверяем доступность бонуса к выплате
            // Для заказов: статус = 'delivered' И бонус не выплачен
            // (is_active уже проверен выше)
            $isOrderDelivered = $order->status && $order->status->slug === 'delivered';
            $isNotPaid = $agentBonus && $agentBonus->paid_at === null;

            $isAvailable = $isOrderDelivered && $isNotPaid;

            // Отправляем только активные заказы на фронтенд
            $ordersData[] = [
                'id' => $order->id,
                'order_number' => $order->order_number ?? '',
                'order_amount' => $order->order_amount,
                'agent_percentage' => $order->agent_percentage ?? 5.0,
                'curator_percentage' => $order->curator_percentage ?? 5.0,
                'agent_bonus' => $agentBonusAmount,
                'curator_bonus' => $curatorBonusAmount,
                'is_active' => $order->is_active,
                'is_available' => $isAvailable,
                'is_paid' => $agentBonus && $agentBonus->paid_at !== null,
            ];

            // Считаем бонусы
            $totalAgentBonus += $agentBonusAmount;
            $totalCuratorBonus += $curatorBonusAmount;
        }

        // Считаем сумму доступных к выплате бонусов из данных, которые мы уже рассчитали
        // Это обеспечивает консистентность с галочками is_available
        $totalAvailableBonus = 0.0;

        foreach ($contractsData as $contractInfo) {
            if ($contractInfo['is_available']) {
                $totalAvailableBonus += (float)($contractInfo['agent_bonus'] ?? 0);
            }
        }

        foreach ($ordersData as $orderInfo) {
            if ($orderInfo['is_available']) {
                $totalAvailableBonus += (float)($orderInfo['agent_bonus'] ?? 0);
            }
        }

        return [
            'contracts' => $contractsData,
            'orders' => $ordersData,
            'totalAgentBonus' => round($totalAgentBonus, 2),
            'totalCuratorBonus' => round($totalCuratorBonus, 2),
            'totalAvailableBonus' => round($totalAvailableBonus, 2),
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
