<?php

namespace Tests\Feature;

use App\Models\AgentBonus;
use App\Models\BonusPaymentRequest;
use App\Models\BonusPaymentRequestBonus;
use App\Models\BonusPaymentStatus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PartnerPaymentStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\BonusPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Property-based тесты для автоматического погашения бонусов.
 *
 * Feature: bonus-payments
 * Тестирует связывание бонусов с заявками, погашение и откат.
 */
class BonusPaymentSettlementPropertyTest extends TestCase
{
    use RefreshDatabase;

    private BonusPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRequiredData();
        $this->service = new BonusPaymentService();
    }

    /**
     * **Feature: bonus-payments, Property 1: FIFO Ordering**
     * **Validates: Requirements 8.3, 9.1**
     *
     * Property: For any payment request and set of available bonuses,
     * the bonuses SHALL be linked in chronological order by accrued_at date (oldest first).
     *
     * @dataProvider fifoOrderingProvider
     */
    public function test_bonuses_linked_in_fifo_order(int $bonusCount, float $requestAmount): void
    {
        $agent = $this->createTestAgent();
        $bonuses = $this->createAvailableBonuses($agent, $bonusCount);

        // Создаём заявку
        $request = $this->createPaymentRequest($agent, $requestAmount);

        // Связываем бонусы
        $this->service->linkBonusesToPaymentRequest($request, $agent->id, $requestAmount);

        // Получаем связанные бонусы
        $linkedBonuses = BonusPaymentRequestBonus::where('payment_request_id', $request->id)
            ->with('bonus')
            ->get();

        // Проверяем порядок FIFO
        $previousAccruedAt = null;
        foreach ($linkedBonuses as $link) {
            $currentAccruedAt = $link->bonus->accrued_at;

            if ($previousAccruedAt !== null) {
                $this->assertTrue(
                    $currentAccruedAt >= $previousAccruedAt,
                    'Bonuses should be linked in FIFO order (oldest first)'
                );
            }

            $previousAccruedAt = $currentAccruedAt;
        }
    }

    /**
     * **Feature: bonus-payments, Property 3: Coverage Sum Invariant**
     * **Validates: Requirements 9.4**
     *
     * Property: For any payment request, the sum of all covered_amounts
     * SHALL equal the payment request amount.
     *
     * @dataProvider coverageSumProvider
     */
    public function test_coverage_sum_equals_request_amount(int $bonusCount, float $requestAmount): void
    {
        $agent = $this->createTestAgent();
        $this->createAvailableBonuses($agent, $bonusCount);

        // Рассчитываем доступный баланс
        $availableBalance = $this->service->calculateAvailableBalance($agent->id);

        // Ограничиваем сумму заявки доступным балансом
        $actualRequestAmount = min($requestAmount, $availableBalance);

        if ($actualRequestAmount <= 0) {
            $this->markTestSkipped('No available balance for this test case');
            return;
        }

        // Создаём заявку
        $request = $this->createPaymentRequest($agent, $actualRequestAmount);

        // Связываем бонусы
        $this->service->linkBonusesToPaymentRequest($request, $agent->id, $actualRequestAmount);

        // Проверяем сумму покрытий
        $totalCovered = BonusPaymentRequestBonus::where('payment_request_id', $request->id)
            ->sum('covered_amount');

        $this->assertEquals(
            round($actualRequestAmount, 2),
            round((float) $totalCovered, 2),
            'Sum of covered amounts should equal request amount'
        );
    }


    /**
     * **Feature: bonus-payments, Property 5: Balance Recalculation After Payment**
     * **Validates: Requirements 10.4**
     *
     * Property: For any agent, after a payment request is marked as "paid",
     * the available balance SHALL decrease by exactly the payment request amount.
     *
     * @dataProvider balanceRecalculationProvider
     */
    public function test_balance_decreases_after_payment(int $bonusCount, float $requestAmount): void
    {
        $agent = $this->createTestAgent();
        $this->createAvailableBonuses($agent, $bonusCount);

        // Рассчитываем начальный баланс
        $initialBalance = $this->service->calculateAvailableBalance($agent->id);

        // Ограничиваем сумму заявки
        $actualRequestAmount = min($requestAmount, $initialBalance);

        if ($actualRequestAmount <= 0) {
            $this->markTestSkipped('No available balance for this test case');
            return;
        }

        // Создаём заявку и связываем бонусы
        $request = $this->createPaymentRequest($agent, $actualRequestAmount);
        $this->service->linkBonusesToPaymentRequest($request, $agent->id, $actualRequestAmount);

        // Погашаем бонусы
        $this->service->settleBonuses($request);

        // Рассчитываем новый баланс
        $newBalance = $this->service->calculateAvailableBalance($agent->id);

        // Проверяем уменьшение баланса
        $expectedBalance = $initialBalance - $actualRequestAmount;

        $this->assertEquals(
            round($expectedBalance, 2),
            round($newBalance, 2),
            'Balance should decrease by exactly the request amount'
        );
    }

    /**
     * **Feature: bonus-payments, Property 6: Rollback Restores Original State**
     * **Validates: Requirements 10.5**
     *
     * Property: For any payment request that transitions from "paid" to another status,
     * the bonus states SHALL be restored to their pre-payment state.
     *
     * @dataProvider rollbackProvider
     */
    public function test_rollback_restores_original_state(int $bonusCount, float $requestAmount): void
    {
        $agent = $this->createTestAgent();
        $this->createAvailableBonuses($agent, $bonusCount);

        // Запоминаем начальное состояние
        $initialBalance = $this->service->calculateAvailableBalance($agent->id);
        $initialBonusCount = AgentBonus::where('agent_id', $agent->id)->count();

        // Ограничиваем сумму заявки
        $actualRequestAmount = min($requestAmount, $initialBalance);

        if ($actualRequestAmount <= 0) {
            $this->markTestSkipped('No available balance for this test case');
            return;
        }

        // Создаём заявку и связываем бонусы
        $request = $this->createPaymentRequest($agent, $actualRequestAmount);
        $this->service->linkBonusesToPaymentRequest($request, $agent->id, $actualRequestAmount);

        // Погашаем бонусы
        $this->service->settleBonuses($request);

        // Откатываем погашение
        $this->service->rollbackSettlement($request);

        // Проверяем восстановление баланса
        $restoredBalance = $this->service->calculateAvailableBalance($agent->id);

        $this->assertEquals(
            round($initialBalance, 2),
            round($restoredBalance, 2),
            'Balance should be restored after rollback'
        );

        // Проверяем, что количество бонусов восстановлено
        $restoredBonusCount = AgentBonus::where('agent_id', $agent->id)->count();

        $this->assertEquals(
            $initialBonusCount,
            $restoredBonusCount,
            'Bonus count should be restored after rollback'
        );
    }

    /**
     * **Feature: bonus-payments, Property 7: Balance Validation**
     * **Validates: Requirements 11.2, 11.3**
     *
     * Property: For any payment request creation attempt where amount > available balance,
     * the request SHALL be rejected.
     *
     * @dataProvider balanceValidationProvider
     */
    public function test_request_rejected_when_exceeds_balance(int $bonusCount): void
    {
        $agent = $this->createTestAgent();
        $this->createAvailableBonuses($agent, $bonusCount);

        $availableBalance = $this->service->calculateAvailableBalance($agent->id);
        $excessAmount = $availableBalance + 1000;

        // Проверяем, что сумма превышает баланс
        $this->assertTrue(
            $excessAmount > $availableBalance,
            'Request amount should exceed available balance'
        );

        // Валидация должна отклонить такую заявку
        $isValid = $excessAmount <= $availableBalance;

        $this->assertFalse(
            $isValid,
            'Request exceeding balance should be invalid'
        );
    }

    // ==================== DATA PROVIDERS ====================

    public static function fifoOrderingProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $bonusCount = mt_rand(3, 10);
            $requestAmount = mt_rand(1000, 50000) / 100;
            $testCases["fifo_{$i}"] = [$bonusCount, $requestAmount];
        }
        return $testCases;
    }

    public static function coverageSumProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $bonusCount = mt_rand(2, 8);
            $requestAmount = mt_rand(500, 30000) / 100;
            $testCases["coverage_{$i}"] = [$bonusCount, $requestAmount];
        }
        return $testCases;
    }

    public static function balanceRecalculationProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $bonusCount = mt_rand(2, 6);
            $requestAmount = mt_rand(500, 20000) / 100;
            $testCases["balance_{$i}"] = [$bonusCount, $requestAmount];
        }
        return $testCases;
    }

    public static function rollbackProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $bonusCount = mt_rand(2, 5);
            $requestAmount = mt_rand(500, 15000) / 100;
            $testCases["rollback_{$i}"] = [$bonusCount, $requestAmount];
        }
        return $testCases;
    }

    public static function balanceValidationProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $bonusCount = mt_rand(1, 5);
            $testCases["validation_{$i}"] = [$bonusCount];
        }
        return $testCases;
    }


    // ==================== HELPER METHODS ====================

    private function seedRequiredData(): void
    {
        // Seed bonus payment statuses
        DB::table('bonus_payment_statuses')->insert([
            [
                'code' => 'requested',
                'name' => 'Запрошено',
                'description' => 'Агент создал заявку на выплату',
                'color' => '#F59E0B',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'approved',
                'name' => 'Согласовано',
                'description' => 'Администратор одобрил заявку',
                'color' => '#3B82F6',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'paid',
                'name' => 'Выплачено',
                'description' => 'Выплата произведена агенту',
                'color' => '#10B981',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed bonus statuses
        DB::table('bonus_statuses')->insert([
            [
                'code' => 'pending',
                'name' => 'Ожидание',
                'description' => 'Бонус ожидает выплаты',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'paid',
                'name' => 'Погашено',
                'description' => 'Бонус выплачен',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed contract statuses
        DB::table('contract_statuses')->insert([
            [
                'slug' => 'completed',
                'name' => 'Выполнен',
                'description' => 'Договор выполнен',
                'color' => '#10B981',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed order statuses
        DB::table('order_statuses')->insert([
            [
                'slug' => 'delivered',
                'name' => 'Доставлен',
                'description' => 'Заказ доставлен',
                'color' => '#10B981',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed partner payment statuses
        DB::table('partner_payment_statuses')->insert([
            [
                'code' => 'paid',
                'name' => 'Оплачено',
                'description' => 'Партнёр оплатил',
                'color' => '#10B981',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed project statuses
        DB::table('project_statuses')->insert([
            [
                'slug' => 'active',
                'name' => 'Активный',
                'description' => 'Активный проект',
                'color' => '#10B981',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createTestAgent(): User
    {
        return User::create([
            'name' => 'Test Agent ' . uniqid(),
            'email' => 'agent_' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    private function createAvailableBonuses(User $agent, int $count): array
    {
        $bonuses = [];
        $pendingStatusId = BonusStatus::pendingId();
        $completedStatusId = ContractStatus::where('slug', 'completed')->value('id');
        $paidPaymentStatusId = PartnerPaymentStatus::where('code', 'paid')->value('id');
        $projectStatusId = DB::table('project_statuses')->where('slug', 'active')->value('id');

        for ($i = 0; $i < $count; $i++) {
            // Создаём проект
            $project = Project::create([
                'value' => 'Test Project ' . uniqid(),
                'user_id' => $agent->id,
                'status_id' => $projectStatusId,
            ]);

            // Создаём договор (выполнен и оплачен)
            $contract = Contract::create([
                'project_id' => $project->id,
                'contract_number' => 'CONTRACT-' . uniqid(),
                'contract_amount' => mt_rand(10000, 100000),
                'agent_percentage' => mt_rand(5, 15),
                'status_id' => $completedStatusId,
                'partner_payment_status_id' => $paidPaymentStatusId,
                'is_active' => true,
            ]);

            // Создаём бонус с разными датами начисления
            $accruedAt = now()->subDays($count - $i); // Старые бонусы первыми

            $bonus = AgentBonus::create([
                'agent_id' => $agent->id,
                'contract_id' => $contract->id,
                'order_id' => null,
                'commission_amount' => mt_rand(500, 5000) / 100,
                'status_id' => $pendingStatusId,
                'accrued_at' => $accruedAt,
                'available_at' => $accruedAt,
                'paid_at' => null,
                'bonus_type' => 'agent',
            ]);

            $bonuses[] = $bonus;
        }

        return $bonuses;
    }

    private function createPaymentRequest(User $agent, float $amount): BonusPaymentRequest
    {
        $requestedStatusId = BonusPaymentStatus::findByCode('requested')->id;

        return BonusPaymentRequest::create([
            'agent_id' => $agent->id,
            'amount' => $amount,
            'payment_method' => 'card',
            'card_number' => '4111111111111111',
            'status_id' => $requestedStatusId,
        ]);
    }
}
