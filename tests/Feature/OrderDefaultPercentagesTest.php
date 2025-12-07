<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Project;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-based тесты для дефолтных процентов при создании закупки.
 *
 * **Feature: bonus-calculation-system, Property 6: Default Percentages for Orders**
 * **Validates: Requirements 1.2**
 */
class OrderDefaultPercentagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **Feature: bonus-calculation-system, Property 6: Default Percentages for Orders**
     * **Validates: Requirements 1.2**
     *
     * Property: For any newly created order without explicit percentages,
     * agent_percentage SHALL default to 5 and curator_percentage SHALL default to 5
     *
     * @dataProvider orderCreationProvider
     */
    public function test_order_has_default_percentages_on_creation(
        ?float $orderAmount,
        bool $isActive
    ): void {
        $project = Project::factory()->create();
        $company = Company::factory()->create();

        // Создаём закупку БЕЗ указания процентов
        $order = Order::create([
            'value' => 'Test Order',
            'project_id' => $project->id,
            'company_id' => $company->id,
            'order_amount' => $orderAmount,
            'is_active' => $isActive,
        ]);

        // Проверяем дефолтные проценты
        $this->assertEquals(
            5.00,
            (float) $order->agent_percentage,
            "Agent percentage should default to 5%"
        );
        $this->assertEquals(
            5.00,
            (float) $order->curator_percentage,
            "Curator percentage should default to 5%"
        );

        // Проверяем расчёт бонусов с дефолтными процентами
        if ($isActive && $orderAmount !== null && $orderAmount > 0) {
            $expectedBonus = round($orderAmount * 5.0 / 100, 2);
            $this->assertEquals($expectedBonus, (float) $order->agent_bonus);
            $this->assertEquals($expectedBonus, (float) $order->curator_bonus);
        } else {
            $this->assertEquals(0.0, (float) $order->agent_bonus);
            $this->assertEquals(0.0, (float) $order->curator_bonus);
        }
    }

    /**
     * Генератор данных для property-based теста дефолтных процентов.
     */
    public static function orderCreationProvider(): array
    {
        $testCases = [];

        // Генерируем 50 случайных комбинаций
        for ($i = 0; $i < 50; $i++) {
            $orderAmount = mt_rand(0, 1) ? mt_rand(1000, 1000000) / 100 : null;
            $isActive = (bool) mt_rand(0, 1);

            $testCases["case_{$i}"] = [$orderAmount, $isActive];
        }

        // Граничные случаи
        $testCases['with_amount_active'] = [50000.00, true];
        $testCases['with_amount_inactive'] = [50000.00, false];
        $testCases['null_amount_active'] = [null, true];
        $testCases['zero_amount_active'] = [0.0, true];

        return $testCases;
    }

    /**
     * Тест: явно указанные проценты переопределяют дефолтные.
     */
    public function test_explicit_percentages_override_defaults(): void
    {
        $project = Project::factory()->create();
        $company = Company::factory()->create();

        $order = Order::create([
            'value' => 'Test Order',
            'project_id' => $project->id,
            'company_id' => $company->id,
            'order_amount' => 100000.00,
            'agent_percentage' => 7.5,
            'curator_percentage' => 3.0,
            'is_active' => true,
        ]);

        $this->assertEquals(7.50, (float) $order->agent_percentage);
        $this->assertEquals(3.00, (float) $order->curator_percentage);
        $this->assertEquals(7500.00, (float) $order->agent_bonus);
        $this->assertEquals(3000.00, (float) $order->curator_bonus);
    }

    /**
     * Тест: бонус пересчитывается при обновлении закупки.
     */
    public function test_bonus_recalculates_on_order_update(): void
    {
        $project = Project::factory()->create();
        $company = Company::factory()->create();

        $order = Order::create([
            'value' => 'Test Order',
            'project_id' => $project->id,
            'company_id' => $company->id,
            'order_amount' => 50000.00,
            'is_active' => true,
        ]);

        // Начальные бонусы (5% от 50000 = 2500)
        $this->assertEquals(2500.00, (float) $order->agent_bonus);
        $this->assertEquals(2500.00, (float) $order->curator_bonus);

        // Обновляем сумму
        $order->update(['order_amount' => 100000.00]);
        $order->refresh();

        // Проверяем пересчёт (5% от 100000 = 5000)
        $this->assertEquals(5000.00, (float) $order->agent_bonus);
        $this->assertEquals(5000.00, (float) $order->curator_bonus);
    }

    /**
     * Тест: деактивация закупки обнуляет бонусы.
     */
    public function test_deactivating_order_zeros_bonuses(): void
    {
        $project = Project::factory()->create();
        $company = Company::factory()->create();

        $order = Order::create([
            'value' => 'Test Order',
            'project_id' => $project->id,
            'company_id' => $company->id,
            'order_amount' => 50000.00,
            'is_active' => true,
        ]);

        // Начальные бонусы
        $this->assertEquals(2500.00, (float) $order->agent_bonus);

        // Деактивируем
        $order->update(['is_active' => false]);
        $order->refresh();

        // Бонусы должны стать 0
        $this->assertEquals(0.0, (float) $order->agent_bonus);
        $this->assertEquals(0.0, (float) $order->curator_bonus);
    }
}
