<?php

namespace Tests\Unit;

use App\Models\Bonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-based tests для scopes модели Bonus.
 *
 * Feature: unified-bonuses-model
 * Property 7: Scope filtering by recipient type
 * Property 8: User-specific scope filtering
 * Validates: Requirements 4.3-4.8
 */
class BonusScopesTest extends TestCase
{
    use RefreshDatabase;

    private BonusStatus $pendingStatus;
    private User $user1;
    private User $user2;
    private Project $project;
    private Contract $contract;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        // Создаём статус бонуса
        $this->pendingStatus = BonusStatus::firstOrCreate(
            ['code' => 'pending'],
            ['name' => 'Ожидание', 'description' => 'Бонус ожидает выплаты']
        );

        // Создаём пользователей
        $this->user1 = User::factory()->create();
        $this->user2 = User::factory()->create();

        // Создаём проект
        $this->project = Project::factory()->create();

        // Создаём договор и заказ
        $this->contract = Contract::factory()->create([
            'project_id' => $this->project->id,
            'contract_amount' => 100000,
        ]);

        $this->order = Order::factory()->create([
            'project_id' => $this->project->id,
            'order_amount' => 50000,
        ]);
    }

    /**
     * @test
     * Property 7: Scope filtering by recipient type - agentBonuses()
     *
     * For any set of bonuses with mixed recipient_types,
     * the scope agentBonuses() SHALL return only records where recipient_type = 'agent'
     */
    public function agent_bonuses_scope_returns_only_agent_bonuses(): void
    {
        // Создаём бонусы разных типов
        for ($i = 0; $i < 100; $i++) {
            $recipientType = ['agent', 'curator', 'referrer'][rand(0, 2)];

            Bonus::create([
                'user_id' => $this->user1->id,
                'contract_id' => $this->contract->id,
                'commission_amount' => rand(100, 10000),
                'status_id' => $this->pendingStatus->id,
                'recipient_type' => $recipientType,
                'accrued_at' => now(),
            ]);
        }

        // Проверяем scope
        $agentBonuses = Bonus::agentBonuses()->get();

        foreach ($agentBonuses as $bonus) {
            $this->assertEquals(
                Bonus::RECIPIENT_AGENT,
                $bonus->recipient_type,
                'agentBonuses() scope должен возвращать только бонусы с recipient_type = agent'
            );
        }
    }

    /**
     * @test
     * Property 7: Scope filtering by recipient type - curatorBonuses()
     *
     * For any set of bonuses with mixed recipient_types,
     * the scope curatorBonuses() SHALL return only records where recipient_type = 'curator'
     */
    public function curator_bonuses_scope_returns_only_curator_bonuses(): void
    {
        // Создаём бонусы разных типов
        for ($i = 0; $i < 100; $i++) {
            $recipientType = ['agent', 'curator', 'referrer'][rand(0, 2)];

            Bonus::create([
                'user_id' => $this->user1->id,
                'contract_id' => $this->contract->id,
                'commission_amount' => rand(100, 10000),
                'status_id' => $this->pendingStatus->id,
                'recipient_type' => $recipientType,
                'accrued_at' => now(),
            ]);
        }

        // Проверяем scope
        $curatorBonuses = Bonus::curatorBonuses()->get();

        foreach ($curatorBonuses as $bonus) {
            $this->assertEquals(
                Bonus::RECIPIENT_CURATOR,
                $bonus->recipient_type,
                'curatorBonuses() scope должен возвращать только бонусы с recipient_type = curator'
            );
        }
    }

    /**
     * @test
     * Property 7: Scope filtering by recipient type - referralBonuses()
     *
     * For any set of bonuses with mixed recipient_types,
     * the scope referralBonuses() SHALL return only records where recipient_type = 'referrer'
     */
    public function referral_bonuses_scope_returns_only_referrer_bonuses(): void
    {
        // Создаём бонусы разных типов
        for ($i = 0; $i < 100; $i++) {
            $recipientType = ['agent', 'curator', 'referrer'][rand(0, 2)];

            Bonus::create([
                'user_id' => $this->user1->id,
                'contract_id' => $this->contract->id,
                'commission_amount' => rand(100, 10000),
                'status_id' => $this->pendingStatus->id,
                'recipient_type' => $recipientType,
                'accrued_at' => now(),
            ]);
        }

        // Проверяем scope
        $referralBonuses = Bonus::referralBonuses()->get();

        foreach ($referralBonuses as $bonus) {
            $this->assertEquals(
                Bonus::RECIPIENT_REFERRER,
                $bonus->recipient_type,
                'referralBonuses() scope должен возвращать только бонусы с recipient_type = referrer'
            );
        }
    }

    /**
     * @test
     * Property 8: User-specific scope filtering - forAgent()
     *
     * For any user_id, the scope forAgent($userId) SHALL return only records
     * where user_id matches AND recipient_type = 'agent'
     */
    public function for_agent_scope_returns_only_agent_bonuses_for_specific_user(): void
    {
        // Создаём бонусы для разных пользователей и типов
        for ($i = 0; $i < 100; $i++) {
            $userId = [$this->user1->id, $this->user2->id][rand(0, 1)];
            $recipientType = ['agent', 'curator', 'referrer'][rand(0, 2)];

            Bonus::create([
                'user_id' => $userId,
                'contract_id' => $this->contract->id,
                'commission_amount' => rand(100, 10000),
                'status_id' => $this->pendingStatus->id,
                'recipient_type' => $recipientType,
                'accrued_at' => now(),
            ]);
        }

        // Проверяем scope для user1
        $user1AgentBonuses = Bonus::forAgent($this->user1->id)->get();

        foreach ($user1AgentBonuses as $bonus) {
            $this->assertEquals($this->user1->id, $bonus->user_id);
            $this->assertEquals(Bonus::RECIPIENT_AGENT, $bonus->recipient_type);
        }

        // Проверяем scope для user2
        $user2AgentBonuses = Bonus::forAgent($this->user2->id)->get();

        foreach ($user2AgentBonuses as $bonus) {
            $this->assertEquals($this->user2->id, $bonus->user_id);
            $this->assertEquals(Bonus::RECIPIENT_AGENT, $bonus->recipient_type);
        }
    }

    /**
     * @test
     * Property 8: User-specific scope filtering - forCurator()
     *
     * For any user_id, the scope forCurator($userId) SHALL return only records
     * where user_id matches AND recipient_type = 'curator'
     */
    public function for_curator_scope_returns_only_curator_bonuses_for_specific_user(): void
    {
        // Создаём бонусы для разных пользователей и типов
        for ($i = 0; $i < 100; $i++) {
            $userId = [$this->user1->id, $this->user2->id][rand(0, 1)];
            $recipientType = ['agent', 'curator', 'referrer'][rand(0, 2)];

            Bonus::create([
                'user_id' => $userId,
                'contract_id' => $this->contract->id,
                'commission_amount' => rand(100, 10000),
                'status_id' => $this->pendingStatus->id,
                'recipient_type' => $recipientType,
                'accrued_at' => now(),
            ]);
        }

        // Проверяем scope для user1
        $user1CuratorBonuses = Bonus::forCurator($this->user1->id)->get();

        foreach ($user1CuratorBonuses as $bonus) {
            $this->assertEquals($this->user1->id, $bonus->user_id);
            $this->assertEquals(Bonus::RECIPIENT_CURATOR, $bonus->recipient_type);
        }
    }

    /**
     * @test
     * Property 8: User-specific scope filtering - forReferrer()
     *
     * For any user_id, the scope forReferrer($userId) SHALL return only records
     * where user_id matches AND recipient_type = 'referrer'
     */
    public function for_referrer_scope_returns_only_referrer_bonuses_for_specific_user(): void
    {
        // Создаём бонусы для разных пользователей и типов
        for ($i = 0; $i < 100; $i++) {
            $userId = [$this->user1->id, $this->user2->id][rand(0, 1)];
            $recipientType = ['agent', 'curator', 'referrer'][rand(0, 2)];

            Bonus::create([
                'user_id' => $userId,
                'contract_id' => $this->contract->id,
                'commission_amount' => rand(100, 10000),
                'status_id' => $this->pendingStatus->id,
                'recipient_type' => $recipientType,
                'accrued_at' => now(),
            ]);
        }

        // Проверяем scope для user1
        $user1ReferrerBonuses = Bonus::forReferrer($this->user1->id)->get();

        foreach ($user1ReferrerBonuses as $bonus) {
            $this->assertEquals($this->user1->id, $bonus->user_id);
            $this->assertEquals(Bonus::RECIPIENT_REFERRER, $bonus->recipient_type);
        }
    }
}
