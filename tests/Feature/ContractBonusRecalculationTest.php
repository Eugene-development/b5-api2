<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Project;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-based тесты для автоматического пересчёта бонусов при обновлении договора.
 *
 * **Feature: bonus-calculation-system, Property 7: Bonus Recalculation on Update**
 * **Validates: Requirements 4.3, 7.4**
 */
class ContractBonusRecalculationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **Feature: bonus-calculation-system, Property 7: Bonus Recalculation on Update**
     * **Validates: Requirements 4.3, 7.4**
     *
     * Property: For any contract, when amount, percentage, or is_active changes,
     * the bonus values SHALL be recalculated and persisted
     *
     * @dataProvider contractUpdateProvider
     */
    public function test_bonus_recalculates_on_contract_update(
        float $initialAmount,
        float $updatedAmount,
        float $agentPercentage,
        float $curatorPercentage,
        bool $isActive
    ): void {
        // Создаём проект и компанию для связи
        $project = Project::factory()->create();
        $company = Company::factory()->create();

        // Создаём договор с начальной суммой
        $contract = Contract::create([
            'project_id' => $project->id,
            'company_id' => $company->id,
            'contract_date' => now(),
            'planned_completion_date' => now()->addMonths(3),
            'contract_amount' => $initialAmount,
            'agent_percentage' => $agentPercentage,
            'curator_percentage' => $curatorPercentage,
            'is_active' => true,
        ]);

        // Проверяем начальный расчёт
        $expectedInitialAgentBonus = round($initialAmount * $agentPercentage / 100, 2);
        $expectedInitialCuratorBonus = round($initialAmount * $curatorPercentage / 100, 2);

        $this->assertEquals($expectedInitialAgentBonus, (float) $contract->agent_bonus);
        $this->assertEquals($expectedInitialCuratorBonus, (float) $contract->curator_bonus);

        // Обновляем сумму и статус
        $contract->update([
            'contract_amount' => $updatedAmount,
            'is_active' => $isActive,
        ]);

        // Проверяем пересчёт
        $contract->refresh();

        if ($isActive && $updatedAmount > 0) {
            $expectedAgentBonus = round($updatedAmount * $agentPercentage / 100, 2);
            $expectedCuratorBonus = round($updatedAmount * $curatorPercentage / 100, 2);
        } else {
            $expectedAgentBonus = 0.0;
            $expectedCuratorBonus = 0.0;
        }

        $this->assertEquals(
            $expectedAgentBonus,
            (float) $contract->agent_bonus,
            "Agent bonus mismatch after update"
        );
        $this->assertEquals(
            $expectedCuratorBonus,
            (float) $contract->curator_bonus,
            "Curator bonus mismatch after update"
        );
    }

    /**
     * Генератор данных для property-based теста пересчёта бонусов.
     */
    public static function contractUpdateProvider(): array
    {
        $testCases = [];

        // Генерируем 50 случайных комбинаций
        for ($i = 0; $i < 50; $i++) {
            $initialAmount = mt_rand(10000, 5000000) / 100;
            $updatedAmount = mt_rand(10000, 5000000) / 100;
            $agentPercentage = mt_rand(0, 1000) / 100; // 0-10%
            $curatorPercentage = mt_rand(0, 1000) / 100; // 0-10%
            $isActive = (bool) mt_rand(0, 1);

            $testCases["case_{$i}"] = [
                $initialAmount,
                $updatedAmount,
                $agentPercentage,
                $curatorPercentage,
                $isActive
            ];
        }

        // Граничные случаи
        $testCases['deactivate_contract'] = [1000000.00, 1000000.00, 3.0, 2.0, false];
        $testCases['zero_amount'] = [1000000.00, 0.0, 3.0, 2.0, true];
        $testCases['increase_amount'] = [100000.00, 500000.00, 3.0, 2.0, true];
        $testCases['decrease_amount'] = [500000.00, 100000.00, 3.0, 2.0, true];

        return $testCases;
    }

    /**
     * Тест: бонус пересчитывается при изменении процента.
     */
    public function test_bonus_recalculates_on_percentage_change(): void
    {
        $project = Project::factory()->create();
        $company = Company::factory()->create();

        $contract = Contract::create([
            'project_id' => $project->id,
            'company_id' => $company->id,
            'contract_date' => now(),
            'planned_completion_date' => now()->addMonths(3),
            'contract_amount' => 1000000.00,
            'agent_percentage' => 3.0,
            'curator_percentage' => 2.0,
            'is_active' => true,
        ]);

        // Начальные бонусы
        $this->assertEquals(30000.00, (float) $contract->agent_bonus);
        $this->assertEquals(20000.00, (float) $contract->curator_bonus);

        // Меняем проценты
        $contract->update([
            'agent_percentage' => 5.0,
            'curator_percentage' => 3.0,
        ]);

        $contract->refresh();

        // Проверяем пересчёт
        $this->assertEquals(50000.00, (float) $contract->agent_bonus);
        $this->assertEquals(30000.00, (float) $contract->curator_bonus);
    }
}
