<?php

namespace Tests\Feature;

use App\Models\AgentBonus;
use App\Models\AgentPayment;
use App\Models\BonusStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Services\BonusService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Property-based тесты для PaymentService.
 *
 * Тестирует создание выплат и обновление статусов бонусов.
 */
class PaymentServicePropertyTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $paymentService;
    private BonusService $bonusService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paymentService = new PaymentService();
        $this->bonusService = new BonusService();
    }

    /**
     * **Feature: bonus-calculation-system, Property 12: Расчёт суммы выплаты**
     * **Validates: Requirements 6.2**
     *
     * Property: For any agent_payment, total_amount must equal the sum of
     * commission_amounts of all linked agent_bonuses.
     *
     * @dataProvider paymentTotalProvider
     */
    public function test_payment_total_calculation(array $commissionAmounts): void
    {
        $agentId = $this->createTestAgent();
        $bonuses = [];

        foreach ($commissionAmounts as $amount) {
            $bonus = $this->createTestBonus($agentId, $amount);
            // Переводим в статус "доступно к выплате"
            $this->bonusService->markBonusAsAvailable($bonus);
            $bonuses[] = $bonus;
        }

        $expectedTotal = round(array_sum($commissionAmounts), 2);
        $calculatedTotal = $this->paymentService->calculatePaymentTotal($bonuses);

        $this->assertEquals($expectedTotal, $calculatedTotal);
    }


    /**
     * **Feature: bonus-calculation-system, Property 13: Связь выплаты с бонусами**
     * **Validates: Requirements 6.3**
     *
     * Property: For any agent_payment with N selected bonuses, exactly N records
     * must exist in agent_payment_bonuses linking that payment to those bonuses.
     *
     * @dataProvider paymentBonusLinkingProvider
     */
    public function test_payment_bonus_linking(int $bonusCount): void
    {
        $agentId = $this->createTestAgent();
        $bonusIds = [];

        for ($i = 0; $i < $bonusCount; $i++) {
            $bonus = $this->createTestBonus($agentId, mt_rand(100, 10000) / 100);
            $this->bonusService->markBonusAsAvailable($bonus);
            $bonusIds[] = $bonus->id;
        }

        $methodId = PaymentMethod::first()->id;
        $payment = $this->paymentService->createPayment($agentId, $bonusIds, $methodId);

        $linkedBonusCount = DB::table('agent_payment_bonuses')
            ->where('payment_id', $payment->id)
            ->count();

        $this->assertEquals($bonusCount, $linkedBonusCount);
        $this->assertEquals($bonusCount, $payment->bonuses()->count());
    }

    /**
     * **Feature: bonus-calculation-system, Property 14: Обновление статусов бонусов при завершении выплаты**
     * **Validates: Requirements 6.4, 6.5**
     *
     * Property: For any agent_payment where status changes to 'completed',
     * all linked agent_bonuses must have status 'paid' and paid_at set.
     *
     * @dataProvider paymentCompletionProvider
     */
    public function test_bonus_status_update_on_payment_completion(int $bonusCount): void
    {
        $agentId = $this->createTestAgent();
        $bonusIds = [];

        for ($i = 0; $i < $bonusCount; $i++) {
            $bonus = $this->createTestBonus($agentId, mt_rand(100, 10000) / 100);
            $this->bonusService->markBonusAsAvailable($bonus);
            $bonusIds[] = $bonus->id;
        }

        $methodId = PaymentMethod::first()->id;
        $payment = $this->paymentService->createPayment($agentId, $bonusIds, $methodId);

        // Завершаем выплату
        $payment = $this->paymentService->completePayment($payment);

        $this->assertEquals('completed', $payment->status->code);

        // Проверяем все связанные бонусы
        foreach ($payment->bonuses as $bonus) {
            $this->assertEquals('paid', $bonus->status->code);
            $this->assertNotNull($bonus->paid_at);
        }
    }


    /**
     * **Feature: bonus-calculation-system, Property 15: Откат статусов бонусов при ошибке выплаты**
     * **Validates: Requirements 6.6**
     *
     * Property: For any agent_payment where status changes to 'failed',
     * all linked agent_bonuses must have status reverted to 'available_for_payment'.
     *
     * @dataProvider paymentFailureProvider
     */
    public function test_bonus_status_revert_on_payment_failure(int $bonusCount): void
    {
        $agentId = $this->createTestAgent();
        $bonusIds = [];

        for ($i = 0; $i < $bonusCount; $i++) {
            $bonus = $this->createTestBonus($agentId, mt_rand(100, 10000) / 100);
            $this->bonusService->markBonusAsAvailable($bonus);
            $bonusIds[] = $bonus->id;
        }

        $methodId = PaymentMethod::first()->id;
        $payment = $this->paymentService->createPayment($agentId, $bonusIds, $methodId);

        // Отмечаем выплату как неудачную
        $payment = $this->paymentService->failPayment($payment);

        $this->assertEquals('failed', $payment->status->code);

        // Проверяем все связанные бонусы
        foreach ($payment->bonuses as $bonus) {
            $this->assertEquals('available_for_payment', $bonus->status->code);
            $this->assertNull($bonus->paid_at);
        }
    }

    // ==================== DATA PROVIDERS ====================

    public static function paymentTotalProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $count = mt_rand(1, 10);
            $amounts = [];
            for ($j = 0; $j < $count; $j++) {
                $amounts[] = mt_rand(100, 100000) / 100;
            }
            $testCases["total_{$i}"] = [$amounts];
        }
        return $testCases;
    }

    public static function paymentBonusLinkingProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $testCases["linking_{$i}"] = [mt_rand(1, 10)];
        }
        return $testCases;
    }

    public static function paymentCompletionProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $testCases["completion_{$i}"] = [mt_rand(1, 10)];
        }
        return $testCases;
    }

    public static function paymentFailureProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $testCases["failure_{$i}"] = [mt_rand(1, 10)];
        }
        return $testCases;
    }


    // ==================== HELPER METHODS ====================

    private function createTestAgent(): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Test Agent ' . uniqid(),
            'email' => 'agent_' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTestBonus(int $agentId, float $commissionAmount): AgentBonus
    {
        $contractId = $this->createTestContract();

        return AgentBonus::create([
            'agent_id' => $agentId,
            'contract_id' => $contractId,
            'order_id' => null,
            'commission_amount' => $commissionAmount,
            'status_id' => BonusStatus::accruedId(),
            'accrued_at' => now(),
        ]);
    }

    private function createTestContract(): string
    {
        $id = \Illuminate\Support\Str::ulid()->toString();
        $projectId = $this->createTestProject();
        $companyId = $this->createTestCompany();

        DB::table('contracts')->insert([
            'id' => $id,
            'project_id' => $projectId,
            'company_id' => $companyId,
            'contract_number' => 'TEST-' . $id,
            'contract_date' => now(),
            'planned_completion_date' => now()->addMonths(3),
            'agent_percentage' => 3.00,
            'curator_percentage' => 2.00,
            'is_active' => true,
            'partner_payment_status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createTestProject(): string
    {
        $id = \Illuminate\Support\Str::ulid()->toString();

        DB::table('projects')->insert([
            'id' => $id,
            'name' => 'Test Project ' . $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createTestCompany(): string
    {
        $id = \Illuminate\Support\Str::ulid()->toString();

        DB::table('companies')->insert([
            'id' => $id,
            'name' => 'Test Company ' . $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
