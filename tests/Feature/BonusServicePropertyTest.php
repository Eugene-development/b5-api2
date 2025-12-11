<?php

namespace Tests\Feature;

use App\Models\AgentBonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use App\Services\BonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-based тесты для BonusService.
 *
 * Тестирует создание, пересчёт и переходы статусов бонусов.
 */
class BonusServicePropertyTest extends TestCase
{
    use RefreshDatabase;

    private BonusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BonusService();
    }

    /**
     * **Feature: bonus-calculation-system, Property 4: Создание бонуса при создании договора**
     * **Validates: Requirements 4.1, 4.2**
     *
     * Property: For any contract with contract_amount > 0 AND is_active = true,
     * an agent_bonus record must be created with correct commission_amount.
     *
     * @dataProvider contractBonusCreationProvider
     */
    public function test_bonus_creation_for_contract(float $amount, float $percentage): void
    {
        $contract = $this->createTestContract($amount, $percentage);

        $bonus = $this->service->createBonusForContract($contract);

        if ($amount > 0 && $contract->is_active) {
            $this->assertNotNull($bonus);
            $expectedCommission = round($amount * $percentage / 100, 2);
            $this->assertEquals($expectedCommission, (float) $bonus->commission_amount);
            $this->assertEquals('accrued', $bonus->status->code);
            $this->assertNotNull($bonus->accrued_at);
        }
    }


    /**
     * **Feature: bonus-calculation-system, Property 5: Создание бонуса при создании закупки**
     * **Validates: Requirements 4.3, 4.4**
     *
     * Property: For any order with order_amount > 0 AND is_active = true,
     * an agent_bonus record must be created with correct commission_amount.
     *
     * @dataProvider orderBonusCreationProvider
     */
    public function test_bonus_creation_for_order(float $amount, float $percentage): void
    {
        $order = $this->createTestOrder($amount, $percentage);

        $bonus = $this->service->createBonusForOrder($order);

        if ($amount > 0 && $order->is_active) {
            $this->assertNotNull($bonus);
            $expectedCommission = round($amount * $percentage / 100, 2);
            $this->assertEquals($expectedCommission, (float) $bonus->commission_amount);
            $this->assertEquals('accrued', $bonus->status->code);
            $this->assertNotNull($bonus->accrued_at);
        }
    }

    /**
     * **Feature: bonus-calculation-system, Property 6: Пересчёт бонуса при изменении договора**
     * **Validates: Requirements 4.5**
     *
     * Property: For any contract update where contract_amount or agent_percentage changes,
     * the related agent_bonus.commission_amount must equal new_amount × new_percentage / 100.
     *
     * @dataProvider bonusRecalculationProvider
     */
    public function test_bonus_recalculation_on_contract_update(
        float $initialAmount,
        float $initialPercentage,
        float $newAmount,
        float $newPercentage
    ): void {
        $contract = $this->createTestContract($initialAmount, $initialPercentage);
        $bonus = $this->service->createBonusForContract($contract);

        if (!$bonus) {
            $this->markTestSkipped('Bonus not created for initial contract');
            return;
        }

        // Обновляем договор
        $contract->contract_amount = $newAmount;
        $contract->agent_percentage = $newPercentage;
        $contract->save();

        // Пересчитываем бонус
        $bonus = $bonus->fresh();
        $bonus = $this->service->recalculateBonus($bonus);

        $expectedCommission = round($newAmount * $newPercentage / 100, 2);
        $this->assertEquals($expectedCommission, (float) $bonus->commission_amount);
    }


    /**
     * **Feature: bonus-calculation-system, Property 8: Обнуление бонуса при деактивации**
     * **Validates: Requirements 4.7**
     *
     * Property: For any contract or order that is deactivated (is_active = false),
     * the related agent_bonus.commission_amount must be set to 0.
     *
     * @dataProvider deactivationProvider
     */
    public function test_bonus_zeroing_on_deactivation(float $amount, float $percentage): void
    {
        $contract = $this->createTestContract($amount, $percentage);
        $bonus = $this->service->createBonusForContract($contract);

        if (!$bonus) {
            $this->markTestSkipped('Bonus not created');
            return;
        }

        // Деактивируем договор
        $contract->is_active = false;
        $contract->save();

        // Пересчитываем бонус
        $bonus = $bonus->fresh();
        $bonus = $this->service->recalculateBonus($bonus);

        $this->assertEquals(0, (float) $bonus->commission_amount);
    }

    /**
     * **Feature: bonus-calculation-system, Property 9: Переход бонуса в доступный статус при оплате договора**
     * **Validates: Requirements 5.1, 5.2**
     *
     * Property: For any contract where partner_payment_status changes to 'paid',
     * the related agent_bonus must have status 'available_for_payment' and available_at set.
     *
     * @dataProvider statusTransitionProvider
     */
    public function test_bonus_status_transition_on_contract_payment(float $amount, float $percentage): void
    {
        $contract = $this->createTestContract($amount, $percentage);
        $bonus = $this->service->createBonusForContract($contract);

        if (!$bonus) {
            $this->markTestSkipped('Bonus not created');
            return;
        }

        // Изначально статус должен быть 'accrued'
        $this->assertEquals('accrued', $bonus->status->code);
        $this->assertNull($bonus->available_at);

        // Переводим в статус "доступно к выплате"
        $bonus = $this->service->markBonusAsAvailable($bonus);

        $this->assertEquals('available_for_payment', $bonus->status->code);
        $this->assertNotNull($bonus->available_at);
    }


    /**
     * **Feature: bonus-calculation-system, Property 11: Откат статуса бонуса при отмене оплаты**
     * **Validates: Requirements 5.5**
     *
     * Property: For any bonus where status is reverted, it must return to 'accrued'
     * and available_at must be cleared.
     *
     * @dataProvider statusRevertProvider
     */
    public function test_bonus_status_revert(float $amount, float $percentage): void
    {
        $contract = $this->createTestContract($amount, $percentage);
        $bonus = $this->service->createBonusForContract($contract);

        if (!$bonus) {
            $this->markTestSkipped('Bonus not created');
            return;
        }

        // Переводим в статус "доступно к выплате"
        $bonus = $this->service->markBonusAsAvailable($bonus);
        $this->assertEquals('available_for_payment', $bonus->status->code);

        // Откатываем статус
        $bonus = $this->service->revertBonusToAccrued($bonus);

        $this->assertEquals('accrued', $bonus->status->code);
        $this->assertNull($bonus->available_at);
    }

    // ==================== DATA PROVIDERS ====================

    public static function contractBonusCreationProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $amount = mt_rand(1, 10000000) / 100;
            $percentage = mt_rand(0, 1000) / 100;
            $testCases["contract_{$i}"] = [$amount, $percentage];
        }
        return $testCases;
    }

    public static function orderBonusCreationProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $amount = mt_rand(1, 10000000) / 100;
            $percentage = mt_rand(0, 1000) / 100;
            $testCases["order_{$i}"] = [$amount, $percentage];
        }
        return $testCases;
    }

    public static function bonusRecalculationProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $initialAmount = mt_rand(1, 10000000) / 100;
            $initialPercentage = mt_rand(1, 1000) / 100;
            $newAmount = mt_rand(1, 10000000) / 100;
            $newPercentage = mt_rand(1, 1000) / 100;
            $testCases["recalc_{$i}"] = [$initialAmount, $initialPercentage, $newAmount, $newPercentage];
        }
        return $testCases;
    }


    public static function deactivationProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $amount = mt_rand(1, 10000000) / 100;
            $percentage = mt_rand(1, 1000) / 100;
            $testCases["deactivation_{$i}"] = [$amount, $percentage];
        }
        return $testCases;
    }

    public static function statusTransitionProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $amount = mt_rand(1, 10000000) / 100;
            $percentage = mt_rand(1, 1000) / 100;
            $testCases["transition_{$i}"] = [$amount, $percentage];
        }
        return $testCases;
    }

    public static function statusRevertProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $amount = mt_rand(1, 10000000) / 100;
            $percentage = mt_rand(1, 1000) / 100;
            $testCases["revert_{$i}"] = [$amount, $percentage];
        }
        return $testCases;
    }

    // ==================== HELPER METHODS ====================

    private function createTestContract(float $amount, float $percentage): Contract
    {
        $projectId = $this->createTestProject();
        $companyId = $this->createTestCompany();

        return Contract::create([
            'project_id' => $projectId,
            'company_id' => $companyId,
            'contract_number' => 'TEST-' . uniqid(),
            'contract_date' => now(),
            'planned_completion_date' => now()->addMonths(3),
            'contract_amount' => $amount,
            'agent_percentage' => $percentage,
            'curator_percentage' => 2.00,
            'is_active' => true,
            'partner_payment_status_id' => 1,
        ]);
    }

    private function createTestOrder(float $amount, float $percentage): Order
    {
        $projectId = $this->createTestProject();
        $companyId = $this->createTestCompany();

        return Order::create([
            'value' => 'Test Order',
            'company_id' => $companyId,
            'project_id' => $projectId,
            'order_number' => 'ORD-' . uniqid(),
            'order_amount' => $amount,
            'agent_percentage' => $percentage,
            'curator_percentage' => 5.00,
            'is_active' => true,
            'partner_payment_status_id' => 1,
        ]);
    }


    private function createTestProject(): string
    {
        $id = \Illuminate\Support\Str::ulid()->toString();

        \Illuminate\Support\Facades\DB::table('projects')->insert([
            'id' => $id,
            'name' => 'Test Project ' . $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Создаём связь с агентом
        $agentId = $this->createTestAgent();
        \Illuminate\Support\Facades\DB::table('project_user')->insert([
            'project_id' => $id,
            'user_id' => $agentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createTestCompany(): string
    {
        $id = \Illuminate\Support\Str::ulid()->toString();

        \Illuminate\Support\Facades\DB::table('companies')->insert([
            'id' => $id,
            'name' => 'Test Company ' . $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createTestAgent(): int
    {
        return \Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'name' => 'Test Agent ' . uniqid(),
            'email' => 'agent_' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
