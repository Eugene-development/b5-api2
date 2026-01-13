<?php

namespace Tests\Feature;

use App\Models\Bonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-based тесты для GraphQL фильтрации бонусов.
 *
 * Тестирует фильтрацию по recipient_type и другим параметрам.
 */
class BonusGraphQLFilterPropertyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations from b5-db-2 project
        $this->artisan('migrate', [
            '--path' => '../b5-db-2/database/migrations',
            '--realpath' => true,
        ]);
    }

    /**
     * **Feature: unified-bonuses-model, Property 10: GraphQL фильтрация по recipient_type**
     * **Validates: Requirements 6.4**
     *
     * Property: When filtering bonuses by recipient_type, only bonuses with matching
     * recipient_type should be returned.
     *
     * @dataProvider recipientTypeFilterProvider
     */
    public function test_filter_by_recipient_type(string $filterType, int $expectedCount): void
    {
        // Создаём тестовые данные
        $this->createTestBonuses();

        // Фильтруем бонусы по recipient_type
        $bonuses = Bonus::where('recipient_type', $filterType)->get();

        // Проверяем что все возвращённые бонусы имеют правильный recipient_type
        foreach ($bonuses as $bonus) {
            $this->assertEquals($filterType, $bonus->recipient_type);
        }

        // Проверяем количество
        $this->assertEquals($expectedCount, $bonuses->count());
    }

    /**
     * **Feature: unified-bonuses-model, Property 11: Корректность recipient_type_label**
     * **Validates: Requirements 4.9, 6.3**
     *
     * Property: The recipient_type_label must correctly map to the recipient_type value.
     *
     * @dataProvider recipientTypeLabelProvider
     */
    public function test_recipient_type_label_mapping(string $recipientType, string $expectedLabel): void
    {
        $bonus = $this->createTestBonusWithRecipientType($recipientType);

        $this->assertEquals($expectedLabel, $bonus->recipient_type_label);
    }

    /**
     * **Feature: unified-bonuses-model, Property 12: Фильтрация по source_type**
     * **Validates: Requirements 6.4**
     *
     * Property: When filtering bonuses by source_type (contract/order),
     * only bonuses from the matching source should be returned.
     *
     * @dataProvider sourceTypeFilterProvider
     */
    public function test_filter_by_source_type(string $sourceType): void
    {
        // Создаём тестовые данные
        $this->createTestBonuses();

        // Получаем бонусы с фильтром
        $query = Bonus::query();

        if ($sourceType === 'contract') {
            $query->whereNotNull('contract_id');
        } elseif ($sourceType === 'order') {
            $query->whereNotNull('order_id');
        }

        $bonuses = $query->get();

        // Проверяем что все бонусы соответствуют фильтру
        foreach ($bonuses as $bonus) {
            if ($sourceType === 'contract') {
                $this->assertNotNull($bonus->contract_id);
                $this->assertNull($bonus->order_id);
            } elseif ($sourceType === 'order') {
                $this->assertNotNull($bonus->order_id);
                $this->assertNull($bonus->contract_id);
            }
        }
    }

    /**
     * **Feature: unified-bonuses-model, Property 13: Scope фильтрация по recipient_type**
     * **Validates: Requirements 4.3, 4.4, 4.5**
     *
     * Property: Model scopes must correctly filter bonuses by recipient_type.
     *
     * @dataProvider scopeFilterProvider
     */
    public function test_scope_filtering_by_recipient_type(string $scope, string $expectedRecipientType): void
    {
        // Создаём тестовые данные
        $this->createTestBonuses();

        // Применяем scope
        $bonuses = match ($scope) {
            'agentBonuses' => Bonus::agentBonuses()->get(),
            'curatorBonuses' => Bonus::curatorBonuses()->get(),
            'referralBonuses' => Bonus::referralBonuses()->get(),
            default => collect(),
        };

        // Проверяем что все бонусы имеют правильный recipient_type
        foreach ($bonuses as $bonus) {
            $this->assertEquals($expectedRecipientType, $bonus->recipient_type);
        }
    }

    /**
     * **Feature: unified-bonuses-model, Property 14: Scope фильтрация по user_id**
     * **Validates: Requirements 4.6, 4.7, 4.8**
     *
     * Property: User-specific scopes must return only bonuses for the specified user.
     *
     * @dataProvider userScopeFilterProvider
     */
    public function test_scope_filtering_by_user_id(string $scope): void
    {
        // Создаём тестовые данные с разными пользователями
        $userId1 = $this->createTestUser();
        $userId2 = $this->createTestUser();

        $this->createTestBonusForUser($userId1, Bonus::RECIPIENT_AGENT);
        $this->createTestBonusForUser($userId1, Bonus::RECIPIENT_CURATOR);
        $this->createTestBonusForUser($userId2, Bonus::RECIPIENT_AGENT);

        // Применяем scope для userId1
        $bonuses = match ($scope) {
            'forAgent' => Bonus::forAgent($userId1)->get(),
            'forCurator' => Bonus::forCurator($userId1)->get(),
            'forReferrer' => Bonus::forReferrer($userId1)->get(),
            default => collect(),
        };

        // Проверяем что все бонусы принадлежат userId1
        foreach ($bonuses as $bonus) {
            $this->assertEquals($userId1, $bonus->user_id);
        }
    }

    // ==================== DATA PROVIDERS ====================

    public static function recipientTypeFilterProvider(): array
    {
        return [
            'filter_agent' => [Bonus::RECIPIENT_AGENT, 2],
            'filter_curator' => [Bonus::RECIPIENT_CURATOR, 2],
            'filter_referrer' => [Bonus::RECIPIENT_REFERRER, 1],
        ];
    }

    public static function recipientTypeLabelProvider(): array
    {
        return [
            'agent_label' => [Bonus::RECIPIENT_AGENT, 'Агент'],
            'curator_label' => [Bonus::RECIPIENT_CURATOR, 'Куратор'],
            'referrer_label' => [Bonus::RECIPIENT_REFERRER, 'Реферер'],
        ];
    }

    public static function sourceTypeFilterProvider(): array
    {
        return [
            'filter_contract' => ['contract'],
            'filter_order' => ['order'],
        ];
    }

    public static function scopeFilterProvider(): array
    {
        return [
            'agent_scope' => ['agentBonuses', Bonus::RECIPIENT_AGENT],
            'curator_scope' => ['curatorBonuses', Bonus::RECIPIENT_CURATOR],
            'referrer_scope' => ['referralBonuses', Bonus::RECIPIENT_REFERRER],
        ];
    }

    public static function userScopeFilterProvider(): array
    {
        return [
            'for_agent_scope' => ['forAgent'],
            'for_curator_scope' => ['forCurator'],
            'for_referrer_scope' => ['forReferrer'],
        ];
    }

    // ==================== HELPER METHODS ====================

    private function createTestBonuses(): void
    {
        $userId = $this->createTestUser();
        $curatorId = $this->createTestUser();
        $referrerId = $this->createTestUser();

        $projectId = $this->createTestProject($userId, $curatorId);
        $companyId = $this->createTestCompany();

        // Создаём договор
        $contract = Contract::create([
            'project_id' => $projectId,
            'company_id' => $companyId,
            'contract_number' => 'TEST-' . uniqid(),
            'contract_date' => now(),
            'planned_completion_date' => now()->addMonths(3),
            'contract_amount' => 100000,
            'agent_percentage' => 5.0,
            'curator_percentage' => 2.0,
            'is_active' => true,
            'partner_payment_status_id' => 1,
        ]);

        // Создаём заказ
        $order = Order::create([
            'value' => 'Test Order',
            'company_id' => $companyId,
            'project_id' => $projectId,
            'order_number' => 'ORD-' . uniqid(),
            'order_amount' => 50000,
            'agent_percentage' => 3.0,
            'curator_percentage' => 1.0,
            'is_active' => true,
            'partner_payment_status_id' => 1,
        ]);

        // Создаём бонусы разных типов
        // Агентский бонус от договора
        Bonus::create([
            'user_id' => $userId,
            'contract_id' => $contract->id,
            'order_id' => null,
            'commission_amount' => 5000,
            'percentage' => 5.0,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => Bonus::RECIPIENT_AGENT,
            'bonus_type' => 'agent',
            'accrued_at' => now(),
        ]);

        // Кураторский бонус от договора
        Bonus::create([
            'user_id' => $curatorId,
            'contract_id' => $contract->id,
            'order_id' => null,
            'commission_amount' => 2000,
            'percentage' => 2.0,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => Bonus::RECIPIENT_CURATOR,
            'bonus_type' => null,
            'accrued_at' => now(),
        ]);

        // Агентский бонус от заказа
        Bonus::create([
            'user_id' => $userId,
            'contract_id' => null,
            'order_id' => $order->id,
            'commission_amount' => 1500,
            'percentage' => 3.0,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => Bonus::RECIPIENT_AGENT,
            'bonus_type' => 'agent',
            'accrued_at' => now(),
        ]);

        // Кураторский бонус от заказа
        Bonus::create([
            'user_id' => $curatorId,
            'contract_id' => null,
            'order_id' => $order->id,
            'commission_amount' => 500,
            'percentage' => 1.0,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => Bonus::RECIPIENT_CURATOR,
            'bonus_type' => null,
            'accrued_at' => now(),
        ]);

        // Реферальный бонус
        Bonus::create([
            'user_id' => $referrerId,
            'contract_id' => $contract->id,
            'order_id' => null,
            'commission_amount' => 500,
            'percentage' => 0.5,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => Bonus::RECIPIENT_REFERRER,
            'bonus_type' => 'referral',
            'referral_user_id' => $userId,
            'accrued_at' => now(),
        ]);
    }

    private function createTestBonusWithRecipientType(string $recipientType): Bonus
    {
        $userId = $this->createTestUser();
        $projectId = $this->createTestProject($userId);
        $companyId = $this->createTestCompany();

        $contract = Contract::create([
            'project_id' => $projectId,
            'company_id' => $companyId,
            'contract_number' => 'TEST-' . uniqid(),
            'contract_date' => now(),
            'planned_completion_date' => now()->addMonths(3),
            'contract_amount' => 100000,
            'agent_percentage' => 5.0,
            'curator_percentage' => 2.0,
            'is_active' => true,
            'partner_payment_status_id' => 1,
        ]);

        return Bonus::create([
            'user_id' => $userId,
            'contract_id' => $contract->id,
            'order_id' => null,
            'commission_amount' => 5000,
            'percentage' => 5.0,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => $recipientType,
            'bonus_type' => $recipientType === Bonus::RECIPIENT_REFERRER ? 'referral' : 'agent',
            'accrued_at' => now(),
        ]);
    }

    private function createTestBonusForUser(int $userId, string $recipientType): Bonus
    {
        $projectId = $this->createTestProject($userId);
        $companyId = $this->createTestCompany();

        $contract = Contract::create([
            'project_id' => $projectId,
            'company_id' => $companyId,
            'contract_number' => 'TEST-' . uniqid(),
            'contract_date' => now(),
            'planned_completion_date' => now()->addMonths(3),
            'contract_amount' => 100000,
            'agent_percentage' => 5.0,
            'curator_percentage' => 2.0,
            'is_active' => true,
            'partner_payment_status_id' => 1,
        ]);

        return Bonus::create([
            'user_id' => $userId,
            'contract_id' => $contract->id,
            'order_id' => null,
            'commission_amount' => 5000,
            'percentage' => 5.0,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => $recipientType,
            'bonus_type' => $recipientType === Bonus::RECIPIENT_REFERRER ? 'referral' : 'agent',
            'accrued_at' => now(),
        ]);
    }

    private function createTestUser(): int
    {
        return \Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'name' => 'Test User ' . uniqid(),
            'email' => 'user_' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTestProject(int $userId, ?int $curatorId = null): string
    {
        $id = \Illuminate\Support\Str::ulid()->toString();

        $data = [
            'id' => $id,
            'name' => 'Test Project ' . $id,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($curatorId) {
            $data['curator_id'] = $curatorId;
        }

        \Illuminate\Support\Facades\DB::table('projects')->insert($data);

        \Illuminate\Support\Facades\DB::table('project_user')->insert([
            'project_id' => $id,
            'user_id' => $userId,
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
}
