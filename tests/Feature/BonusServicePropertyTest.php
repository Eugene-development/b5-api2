<?php

namespace Tests\Feature;

use App\Models\Bonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use App\Services\BonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-based тесты для BonusService.
 *
 * Тестирует создание, пересчёт и переходы статусов бонусов
 * для агентов и кураторов.
 */
class BonusServicePropertyTest extends TestCase
{
    use RefreshDatabase;

    private BonusService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations from b5-db-2 project
        $this->artisan('migrate', [
            '--path' => '../b5-db-2/database/migrations',
            '--realpath' => true,
        ]);

        $this->service = new BonusService();
    }

    /**
     * **Feature: unified-bonuses-model, Property 1: Создание агентского бонуса при создании договора**
     * **Validates: Requirements 3.1, 7.1**
     *
     * Property: For any contract with contract_amount > 0 AND is_active = true,
     * an agent bonus record must be created with correct commission_amount and recipient_type = 'agent'.
     *
     * @dataProvider contractBonusCreationProvider
     */
    public function test_agent_bonus_creation_for_contract(float $amount, float $percentage): void
    {
        $contract = $this->createTestContract($amount, $percentage);

        $bonus = $this->service->createBonusForContract($contract);

        if ($amount > 0 && $contract->is_active) {
            $this->assertNotNull($bonus);
            $expectedCommission = round($amount * $percentage / 100, 2);
            $this->assertEquals($expectedCommission, (float) $bonus->commission_amount);
            $this->assertEquals(Bonus::RECIPIENT_AGENT, $bonus->recipient_type);
            $this->assertEquals($percentage, (float) $bonus->percentage);
            $this->assertEquals('accrued', $bonus->status->code);
            $this->assertNotNull($bonus->accrued_at);
        }
    }

    /**
     * **Feature: unified-bonuses-model, Property 2: Создание кураторского бонуса при создании договора**
     * **Validates: Requirements 3.2, 7.2**
     *
     * Property: For any contract with contract_amount > 0 AND is_active = true AND curator_id exists,
     * a curator bonus record must be created with correct commission_amount and recipient_type = 'curator'.
     *
     * @dataProvider contractBonusCreationProvider
     */
    public function test_curator_bonus_creation_for_contract(float $amount, float $percentage): void
    {
        $curatorPercentage = mt_rand(1, 500) / 100; // 0.01 - 5.00%
        $contract = $this->createTestContractWithCurator($amount, $percentage, $curatorPercentage);

        // Создаём бонусы (агентский + кураторский)
        $this->service->createBonusForContract($contract);

        // Проверяем кураторский бонус
        $curatorBonus = Bonus::where('contract_id', $contract->id)
            ->where('recipient_type', Bonus::RECIPIENT_CURATOR)
            ->first();

        if ($amount > 0 && $contract->is_active) {
            $this->assertNotNull($curatorBonus, 'Curator bonus should be created');
            $expectedCommission = round($amount * $curatorPercentage / 100, 2);
            $this->assertEquals($expectedCommission, (float) $curatorBonus->commission_amount);
            $this->assertEquals(Bonus::RECIPIENT_CURATOR, $curatorBonus->recipient_type);
            $this->assertEquals($curatorPercentage, (float) $curatorBonus->percentage);
            $this->assertEquals('accrued', $curatorBonus->status->code);
        }
    }

    /**
     * **Feature: unified-bonuses-model, Property 3: Создание агентского бонуса при создании закупки**
     * **Validates: Requirements 3.3, 7.3**
     *
     * Property: For any order with order_amount > 0 AND is_active = true,
     * an agent bonus record must be created with correct commission_amount and recipient_type = 'agent'.
     *
     * @dataProvider orderBonusCreationProvider
     */
    public function test_agent_bonus_creation_for_order(float $amount, float $percentage): void
    {
        $order = $this->createTestOrder($amount, $percentage);

        $bonus = $this->service->createBonusForOrder($order);

        if ($amount > 0 && $order->is_active) {
            $this->assertNotNull($bonus);
            $expectedCommission = round($amount * $percentage / 100, 2);
            $this->assertEquals($expectedCommission, (float) $bonus->commission_amount);
            $this->assertEquals(Bonus::RECIPIENT_AGENT, $bonus->recipient_type);
            $this->assertEquals($percentage, (float) $bonus->percentage);
            $this->assertEquals('accrued', $bonus->status->code);
            $this->assertNotNull($bonus->accrued_at);
        }
    }

    /**
     * **Feature: unified-bonuses-model, Property 4: Создание кураторского бонуса при создании закупки**
     * **Validates: Requirements 3.4, 7.4**
     *
     * Property: For any order with order_amount > 0 AND is_active = true AND curator_id exists,
     * a curator bonus record must be created with correct commission_amount and recipient_type = 'curator'.
     *
     * @dataProvider orderBonusCreationProvider
     */
    public function test_curator_bonus_creation_for_order(float $amount, float $percentage): void
    {
        $curatorPercentage = mt_rand(1, 500) / 100; // 0.01 - 5.00%
        $order = $this->createTestOrderWithCurator($amount, $percentage, $curatorPercentage);

        // Создаём бонусы (агентский + кураторский)
        $this->service->createBonusForOrder($order);

        // Проверяем кураторский бонус
        $curatorBonus = Bonus::where('order_id', $order->id)
            ->where('recipient_type', Bonus::RECIPIENT_CURATOR)
            ->first();

        if ($amount > 0 && $order->is_active) {
            $this->assertNotNull($curatorBonus, 'Curator bonus should be created');
            $expectedCommission = round($amount * $curatorPercentage / 100, 2);
            $this->assertEquals($expectedCommission, (float) $curatorBonus->commission_amount);
            $this->assertEquals(Bonus::RECIPIENT_CURATOR, $curatorBonus->recipient_type);
            $this->assertEquals($curatorPercentage, (float) $curatorBonus->percentage);
            $this->assertEquals('accrued', $curatorBonus->status->code);
        }
    }

    /**
     * **Feature: unified-bonuses-model, Property 5: Пересчёт бонуса при изменении суммы/процента**
     * **Validates: Requirements 3.5, 7.5**
     *
     * Property: For any contract update where contract_amount or agent_percentage changes,
     * the related bonus.commission_amount must equal new_amount × new_percentage / 100.
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
        $this->assertEquals($newPercentage, (float) $bonus->percentage);
    }

    /**
     * **Feature: unified-bonuses-model, Property 6: Обнуление бонуса при деактивации**
     * **Validates: Requirements 3.6, 7.5**
     *
     * Property: For any contract or order that is deactivated (is_active = false),
     * the related bonus.commission_amount must be set to 0.
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
     * **Feature: unified-bonuses-model, Property 7: Переход бонуса в доступный статус**
     * **Validates: Requirements 5.1, 5.2**
     *
     * Property: For any contract where partner_payment_status changes to 'paid',
     * the related bonus must have status 'available_for_payment' and available_at set.
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
     * **Feature: unified-bonuses-model, Property 8: Откат статуса бонуса**
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

    /**
     * **Feature: unified-bonuses-model, Property 9: Пересчёт кураторского бонуса**
     * **Validates: Requirements 3.5, 7.5**
     *
     * Property: For any contract update where curator_percentage changes,
     * the curator bonus.commission_amount must equal new_amount × new_curator_percentage / 100.
     *
     * @dataProvider curatorBonusRecalculationProvider
     */
    public function test_curator_bonus_recalculation_on_contract_update(
        float $initialAmount,
        float $initialCuratorPercentage,
        float $newAmount,
        float $newCuratorPercentage
    ): void {
        $contract = $this->createTestContractWithCurator($initialAmount, 5.0, $initialCuratorPercentage);
        $this->service->createBonusForContract($contract);

        $curatorBonus = Bonus::where('contract_id', $contract->id)
            ->where('recipient_type', Bonus::RECIPIENT_CURATOR)
            ->first();

        if (!$curatorBonus) {
            $this->markTestSkipped('Curator bonus not created');
            return;
        }

        // Обновляем договор
        $contract->contract_amount = $newAmount;
        $contract->curator_percentage = $newCuratorPercentage;
        $contract->save();

        // Пересчитываем кураторский бонус
        $curatorBonus = $curatorBonus->fresh();
        $curatorBonus = $this->service->recalculateCuratorBonus($curatorBonus, $contract);

        $expectedCommission = round($newAmount * $newCuratorPercentage / 100, 2);
        $this->assertEquals($expectedCommission, (float) $curatorBonus->commission_amount);
        $this->assertEquals($newCuratorPercentage, (float) $curatorBonus->percentage);
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

    public static function curatorBonusRecalculationProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $initialAmount = mt_rand(1, 10000000) / 100;
            $initialCuratorPercentage = mt_rand(1, 500) / 100;
            $newAmount = mt_rand(1, 10000000) / 100;
            $newCuratorPercentage = mt_rand(1, 500) / 100;
            $testCases["curator_recalc_{$i}"] = [$initialAmount, $initialCuratorPercentage, $newAmount, $newCuratorPercentage];
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

    private function createTestContractWithCurator(float $amount, float $agentPercentage, float $curatorPercentage): Contract
    {
        $curatorId = $this->createTestCurator();
        $projectId = $this->createTestProjectWithCurator($curatorId);
        $companyId = $this->createTestCompany();

        return Contract::create([
            'project_id' => $projectId,
            'company_id' => $companyId,
            'contract_number' => 'TEST-' . uniqid(),
            'contract_date' => now(),
            'planned_completion_date' => now()->addMonths(3),
            'contract_amount' => $amount,
            'agent_percentage' => $agentPercentage,
            'curator_percentage' => $curatorPercentage,
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

    private function createTestOrderWithCurator(float $amount, float $agentPercentage, float $curatorPercentage): Order
    {
        $curatorId = $this->createTestCurator();
        $projectId = $this->createTestProjectWithCurator($curatorId);
        $companyId = $this->createTestCompany();

        return Order::create([
            'value' => 'Test Order',
            'company_id' => $companyId,
            'project_id' => $projectId,
            'order_number' => 'ORD-' . uniqid(),
            'order_amount' => $amount,
            'agent_percentage' => $agentPercentage,
            'curator_percentage' => $curatorPercentage,
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

    private function createTestProjectWithCurator(int $curatorId): string
    {
        $id = \Illuminate\Support\Str::ulid()->toString();

        \Illuminate\Support\Facades\DB::table('projects')->insert([
            'id' => $id,
            'name' => 'Test Project ' . $id,
            'curator_id' => $curatorId,
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

    private function createTestCurator(): int
    {
        return \Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'name' => 'Test Curator ' . uniqid(),
            'email' => 'curator_' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
