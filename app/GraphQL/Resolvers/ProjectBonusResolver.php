<?php

namespace App\GraphQL\Resolvers;

use App\Models\Project;
use App\Services\BonusCalculationService;

/**
 * GraphQL резолвер для получения данных о бонусах проекта.
 *
 * Предоставляет агрегированную информацию о бонусах агента и куратора
 * по всем договорам и закупкам проекта.
 */
class ProjectBonusResolver
{
    private BonusCalculationService $bonusService;

    public function __construct(BonusCalculationService $bonusService)
    {
        $this->bonusService = $bonusService;
    }

    /**
     * Получить общий бонус агента по проекту.
     * Сумма бонусов из всех договоров и закупок проекта.
     *
     * @param Project $project
     * @return float
     */
    public function totalAgentBonus(Project $project): float
    {
        return $this->bonusService->getTotalAgentBonus($project->id);
    }

    /**
     * Получить общий бонус куратора по проекту.
     * Сумма бонусов из всех договоров и закупок проекта.
     *
     * @param Project $project
     * @return float
     */
    public function totalCuratorBonus(Project $project): float
    {
        return $this->bonusService->getTotalCuratorBonus($project->id);
    }

    /**
     * Получить детализацию бонусов по проекту.
     * Включает списки договоров и закупок с их бонусами.
     *
     * @param Project $project
     * @return array{
     *   contracts: array,
     *   orders: array,
     *   totalAgentBonus: float,
     *   totalCuratorBonus: float
     * }
     */
    public function bonusDetails(Project $project): array
    {
        return $this->bonusService->getProjectBonusSummary($project->id);
    }
}
