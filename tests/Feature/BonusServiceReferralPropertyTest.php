<?php

namespace Tests\Feature;

use App\Models\AgentBonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use App\Services\BonusService;
use App\Services\ReferralBonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-based тесты для интеграции BonusService с реферальными бонусами.
 *
 * Проверяют инварианты:
 * - Property 3: Создание реферального бонуса при сделке
 * - Property 5: Отсутствие реферального бонуса для агентов без реферера
 * - Property 7: Ограничение срока реферальной программы
 */
class BonusServiceReferralPropertyTest extends TestCase
{
    use RefreshDatabase;

    private BonusService $bonusService;
    private ReferralBonusService $referralBonusService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->referralBonusService = new ReferralBonusService();
        $this->bonusService = new BonusService($this->referralBonusService);
    }

    /**
     * Property 3: Создание реферального бонуса при сделке.
     *
     * Когда агент с реферером создаёт договор/заказ,
     * реферер получает реферальный бонус 0.5% от суммы.
     *
     * @test
     */
    public function property_referral_bonus_created_when_referral_makes_deal(): void
    {
        // Создаём реферера и реферала
        $referrer = User::factory()->create();
        $referral = User::factory()->create([
            'user_id' => $referrer->id,
            'created_at' => now()->subMonth(), // Недавно зарегистрирован
        ]);

        // Создаём проект для реферала
        $project = Project::factory()->create(['user_id' => $referral->id]);

        // Создаём статус бонуса
        BonusStatus::factory()->create(['code' => 'pending']);

        $iterations = 10;

        for ($i = 0; $i < $iterations; $i++) {
            $contractAmount = mt_rand(10000, 1000000) / 100;

            // Создаём договор
            $contract = Contract::factory()->create([
                'project_id' => $project->id,
                'contract_amount' => $contractAmount,
                'agent_percentage' => 5.0,
                'is_active' => true,
            ]);

            // Создаём бонус через BonusService
            $agentBonus = $this->bonusService->createBonusForContract($contract);

            // Проверяем, что агентский бонус создан
            $this->assertNotNull($agentBonus);
            $this->assertEquals('agent', $agentBonus->bonus_type);
            $this->assertEquals($referral->id, $agentBonus->agent_id);

            // Проверяем, что реферальный бонус создан для реферера
            $referralBonus = AgentBonus::where('agent_id', $referrer->id)
                ->where('bonus_type', 'referral')
                ->where('contract_id', $contract->id)
                ->first();

            $this->assertNotNull($referralBonus, "Referral bonus should be created for referrer");
            $this->assertEquals($referral->id, $referralBonus->referral_user_id);

            // Проверяем сумму реферального бонуса (0.5%)
            $expectedReferralCommission = round($contractAmount * 0.5 / 100, 2);
            $this->assertEquals(
                $expectedReferralCommission,
                (float) $referralBonus->commission_amount,
                "Referral commission should be 0.5% of contract amount"
            );
        }
    }

    /**
     * Property 3.1: Реферальный бонус создаётся для заказов.
     *
     * @test
     */
    public function property_referral_bonus_created_for_orders(): void
    {
        $referrer = User::factory()->create();
        $referral = User::factory()->create([
            'user_id' => $referrer->id,
            'created_at' => now()->subMonth(),
        ]);

        $project = Project::factory()->create(['user_id' => $referral->id]);
        BonusStatus::factory()->create(['code' => 'pending']);

        $iterations = 10;

        for ($i = 0; $i < $iterations; $i++) {
            $orderAmount = mt_rand(10000, 1000000) / 100;

            $order = Order::factory()->create([
                'project_id' => $project->id,
                'order_amount' => $orderAmount,
                'agent_percentage' => 3.0,
                'is_active' => true,
            ]);

            $agentBonus = $this->bonusService->createBonusForOrder($order);

            $this->assertNotNull($agentBonus);
            $this->assertEquals('agent', $agentBonus->bonus_type);

            $referralBonus = AgentBonus::where('agent_id', $referrer->id)
                ->where('bonus_type', 'referral')
                ->where('order_id', $order->id)
                ->first();

            $this->assertNotNull($referralBonus);
            $this->assertEquals($referral->id, $referralBonus->referral_user_id);

            $expectedReferralCommission = round($orderAmount * 0.5 / 100, 2);
            $this->assertEquals($expectedReferralCommission, (float) $referralBonus->commission_amount);
        }
    }

    /**
     * Property 5: Отсутствие реферального бонуса для агентов без реферера.
     *
     * Если у агента нет реферера (user_id = null),
     * реферальный бонус не создаётся.
     *
     * @test
     */
    public function property_no_referral_bonus_for_agents_without_referrer(): void
    {
        // Создаём агента БЕЗ реферера
        $agent = User::factory()->create(['user_id' => null]);
        $project = Project::factory()->create(['user_id' => $agent->id]);
        BonusStatus::factory()->create(['code' => 'pending']);

        $iterations = 10;

        for ($i = 0; $i < $iterations; $i++) {
            $contractAmount = mt_rand(10000, 1000000) / 100;

            $contract = Contract::factory()->create([
                'project_id' => $project->id,
                'contract_amount' => $contractAmount,
                'agent_percentage' => 5.0,
                'is_active' => true,
            ]);

            $agentBonus = $this->bonusService->createBonusForContract($contract);

            // Агентский бонус должен быть создан
            $this->assertNotNull($agentBonus);
            $this->assertEquals('agent', $agentBonus->bonus_type);

            // Реферальный бонус НЕ должен быть создан
            $referralBonusCount = AgentBonus::where('bonus_type', 'referral')
                ->where('contract_id', $contract->id)
                ->count();

            $this->assertEquals(
                0,
                $referralBonusCount,
                "No referral bonus should be created for agent without referrer"
            );
        }
    }

    /**
     * Property 5.1: Отсутствие реферального бонуса для заказов агентов без реферера.
     *
     * @test
     */
    public function property_no_referral_bonus_for_orders_without_referrer(): void
    {
        $agent = User::factory()->create(['user_id' => null]);
        $project = Project::factory()->create(['user_id' => $agent->id]);
        BonusStatus::factory()->create(['code' => 'pending']);

        $iterations = 10;

        for ($i = 0; $i < $iterations; $i++) {
            $orderAmount = mt_rand(10000, 1000000) / 100;

            $order = Order::factory()->create([
                'project_id' => $project->id,
                'order_amount' => $orderAmount,
                'agent_percentage' => 3.0,
                'is_active' => true,
            ]);

            $agentBonus = $this->bonusService->createBonusForOrder($order);

            $this->assertNotNull($agentBonus);

            $referralBonusCount = AgentBonus::where('bonus_type', 'referral')
                ->where('order_id', $order->id)
                ->count();

            $this->assertEquals(0, $referralBonusCount);
        }
    }

    /**
     * Property 7: Ограничение срока реферальной программы.
     *
     * Реферальный бонус НЕ создаётся, если реферал
     * зарегистрирован более 2 лет назад.
     *
     * @test
     */
    public function property_no_referral_bonus_after_program_expiration(): void
    {
        $referrer = User::factory()->create();

        // Создаём реферала, зарегистрированного 3 года назад
        $expiredReferral = User::factory()->create([
            'user_id' => $referrer->id,
            'created_at' => now()->subYears(3),
        ]);

        $project = Project::factory()->create(['user_id' => $expiredReferral->id]);
        BonusStatus::factory()->create(['code' => 'pending']);

        $contractAmount = 10000.00;

        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'contract_amount' => $contractAmount,
            'agent_percentage' => 5.0,
            'is_active' => true,
        ]);

        $agentBonus = $this->bonusService->createBonusForContract($contract);

        // Агентский бонус должен быть создан
        $this->assertNotNull($agentBonus);

        // Реферальный бонус НЕ должен быть создан (срок истёк)
        $referralBonusCount = AgentBonus::where('bonus_type', 'referral')
            ->where('contract_id', $contract->id)
            ->count();

        $this->assertEquals(
            0,
            $referralBonusCount,
            "No referral bonus should be created after program expiration"
        );
    }

    /**
     * Property 7.1: Реферальный бонус создаётся в пределах срока программы.
     *
     * @test
     */
    public function property_referral_bonus_created_within_program_period(): void
    {
        $referrer = User::factory()->create();

        // Создаём реферала, зарегистрированного 1 год назад (в пределах срока)
        $activeReferral = User::factory()->create([
            'user_id' => $referrer->id,
            'created_at' => now()->subYear(),
        ]);

        $project = Project::factory()->create(['user_id' => $activeReferral->id]);
        BonusStatus::factory()->create(['code' => 'pending']);

        $contractAmount = 10000.00;

        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'contract_amount' => $contractAmount,
            'agent_percentage' => 5.0,
            'is_active' => true,
        ]);

        $this->bonusService->createBonusForContract($contract);

        // Реферальный бонус ДОЛЖЕН быть создан
        $referralBonus = AgentBonus::where('bonus_type', 'referral')
            ->where('contract_id', $contract->id)
            ->first();

        $this->assertNotNull(
            $referralBonus,
            "Referral bonus should be created within program period"
        );
        $this->assertEquals($referrer->id, $referralBonus->agent_id);
    }
}
