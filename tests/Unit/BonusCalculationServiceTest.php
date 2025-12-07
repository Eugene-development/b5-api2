<?php

namespace Tests\Unit;

use App\Services\BonusCalculationService;
use PHPUnit\Framework\TestCase;

/**
 * Property-based тесты для BonusCalculationService.
 *
 * Тесты проверяют корректность расчёта бонусов согласно формуле:
 * bonus = amount × percentage / 100
 *
 * **Feature: bonus-calculation-system, Property 1: Bonus Calculation Formula**
 * **Validates: Requirements 3.1, 3.2, 3.3, 3.4**
 */
class BonusCalculationServiceTest extends TestCase
{
    private BonusCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BonusCalculationService();
    }

    /**
     * **Feature: bonus-calculation-system, Property 1: Bonus Calculation Formula**
     * **Validates: Requirements 3.1, 3.2, 3.3, 3.4**
     *
     * Property: For any contract or order with amount > 0 and is_active = true,
     * the bonus SHALL equal amount × percentage / 100
     *
     * @dataProvider validBonusCalculationProvider
     */
    public function test_bonus_calculation_formula_property(float $amount, float $percentage): void
    {
        $expectedBonus = round($amount * $percentage / 100, 2);

        $actualBonus = $this->service->calculateBonus($amount, $percentage, true);

        $this->assertEquals(
            $expectedBonus,
            $actualBonus,
            "Bonus calculation failed for amount={$amount}, percentage={$percentage}"
        );
    }

    /**
     * Генератор данных для property-based теста формулы расчёта.
     * Генерирует 100+ комбинаций сумм и процентов.
     */
    public static function validBonusCalculationProvider(): array
    {
        $testCases = [];

        // Генерируем 100 случайных комбинаций
        for ($i = 0; $i < 100; $i++) {
            $amount = mt_rand(1, 10000000) / 100; // 0.01 - 100000.00
            $percentage = mt_rand(0, 10000) / 100; // 0.00 - 100.00
            $testCases["amount_{$amount}_percent_{$percentage}"] = [$amount, $percentage];
        }

        // Добавляем граничные случаи
        $testCases['min_amount_min_percent'] = [0.01, 0.0];
        $testCases['min_amount_max_percent'] = [0.01, 100.0];
        $testCases['large_amount_small_percent'] = [1000000.00, 0.01];
        $testCases['typical_contract'] = [1500000.00, 3.0]; // Типичный договор
        $testCases['typical_order'] = [50000.00, 5.0]; // Типичная закупка

        return $testCases;
    }

    /**
     * **Feature: bonus-calculation-system, Property 2: Zero Bonus for Inactive or No-Amount Items**
     * **Validates: Requirements 3.5, 3.6**
     *
     * Property: For any contract or order where amount is null OR is_active = false,
     * both agent_bonus and curator_bonus SHALL equal 0
     *
     * @dataProvider zeroBonusConditionsProvider
     */
    public function test_zero_bonus_for_inactive_or_no_amount_property(
        ?float $amount,
        float $percentage,
        bool $isActive
    ): void {
        $bonus = $this->service->calculateBonus($amount, $percentage, $isActive);

        $this->assertEquals(
            0.0,
            $bonus,
            "Expected zero bonus for amount={$amount}, percentage={$percentage}, isActive=" . ($isActive ? 'true' : 'false')
        );
    }

    /**
     * Генератор данных для property-based теста нулевых бонусов.
     */
    public static function zeroBonusConditionsProvider(): array
    {
        $testCases = [];

        // Случай 1: is_active = false (100 итераций)
        for ($i = 0; $i < 50; $i++) {
            $amount = mt_rand(1, 10000000) / 100;
            $percentage = mt_rand(0, 10000) / 100;
            $testCases["inactive_amount_{$amount}_percent_{$percentage}"] = [$amount, $percentage, false];
        }

        // Случай 2: amount = null (50 итераций)
        for ($i = 0; $i < 25; $i++) {
            $percentage = mt_rand(0, 10000) / 100;
            $testCases["null_amount_percent_{$percentage}_active"] = [null, $percentage, true];
            $testCases["null_amount_percent_{$percentage}_inactive"] = [null, $percentage, false];
        }

        // Случай 3: amount = 0 (граничный случай)
        $testCases['zero_amount_active'] = [0.0, 50.0, true];
        $testCases['zero_amount_inactive'] = [0.0, 50.0, false];

        // Случай 4: отрицательная сумма (невалидный ввод)
        $testCases['negative_amount_active'] = [-100.0, 50.0, true];

        return $testCases;
    }

    /**
     * Тест: бонус корректно округляется до 2 знаков после запятой.
     */
    public function test_bonus_rounds_to_two_decimal_places(): void
    {
        // 100.00 × 3.33 / 100 = 3.33
        $bonus = $this->service->calculateBonus(100.00, 3.33, true);
        $this->assertEquals(3.33, $bonus);

        // 100.00 × 3.335 / 100 = 3.335 → округляется до 3.34
        $bonus = $this->service->calculateBonus(100.00, 3.335, true);
        $this->assertEquals(3.34, $bonus);
    }

    /**
     * Тест: невалидный процент (> 100) возвращает 0.
     */
    public function test_invalid_percentage_over_100_returns_zero(): void
    {
        $bonus = $this->service->calculateBonus(1000.00, 150.0, true);
        $this->assertEquals(0.0, $bonus);
    }

    /**
     * Тест: невалидный процент (< 0) возвращает 0.
     */
    public function test_invalid_percentage_negative_returns_zero(): void
    {
        $bonus = $this->service->calculateBonus(1000.00, -10.0, true);
        $this->assertEquals(0.0, $bonus);
    }

    /**
     * Тест: типичный расчёт для договора (3% агенту).
     */
    public function test_typical_contract_agent_bonus(): void
    {
        // Договор на 1,500,000 руб, агент 3%
        $bonus = $this->service->calculateBonus(1500000.00, 3.0, true);
        $this->assertEquals(45000.00, $bonus);
    }

    /**
     * Тест: типичный расчёт для договора (2% куратору).
     */
    public function test_typical_contract_curator_bonus(): void
    {
        // Договор на 1,500,000 руб, куратор 2%
        $bonus = $this->service->calculateBonus(1500000.00, 2.0, true);
        $this->assertEquals(30000.00, $bonus);
    }

    /**
     * Тест: типичный расчёт для закупки (5% агенту и куратору).
     */
    public function test_typical_order_bonus(): void
    {
        // Закупка на 50,000 руб, 5%
        $bonus = $this->service->calculateBonus(50000.00, 5.0, true);
        $this->assertEquals(2500.00, $bonus);
    }
}
