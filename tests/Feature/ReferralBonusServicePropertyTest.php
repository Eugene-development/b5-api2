<?php

namespace Tests\Feature;

use App\Models\AgentBonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use App\Models\User;
use App\Services\ReferralBonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-based тесты для ReferralBonusService.
 *
 * Проверяют инварианты:
 * - Property 4: Расчёт суммы реферального бонуса (0.5% от суммы)
 * - Property 6: Корректная типизация бонусов
 * - Property 7: Ограничение срока реферальной программы
 */
class ReferralBonusServicePropertyTest extends TestCase
{
    use RefreshDatabase;

    private ReferralBonusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReferralBonusService();
    }

    /**
     * Property 4: Расчёт суммы реферального бонуса.
     *
     * Для любой положительной суммы сделки:
     * - Реферальный бонус = 0.5% от суммы
     * - Результат округлён до 2 знаков
     *
     * @test
     */
    public function property_referral_commission_is_half_percent_of_amount(): void
    {
        $iterations = 100;

        for ($i = 0; $i < $iterations; $i++) {
            // Генерируем случайную сумму от 100 до 10,000,000
            $amount = mt_rand(100, 10000000) / 100;

            $commission = $this->service->calculateReferralCommission($amount);

            // Проверяем, что комиссия = 0.5% от суммы
            $expectedCommission = round($amount * 0.5 / 100, 2);

            $this->assertEquals(
                $expectedCommission,
                $commission,
                "Commission for amount {$amount} should be {$expectedCommission}, got {$commission}"
            );
        }
    }

    /**
     * Property 4.1: Нулевая или отрицательная сумма даёт нулевую комиссию.
     *
     * @test
     */
    public function property_zero_or_negative_amount_gives_zero_commission(): void
    {
        $iterations = 100;

        for ($i = 0; $i < $iterations; $i++) {
            // Генерируем отрицательную или нулевую сумму
            $amount = -mt_rand(0, 1000000) / 100;

            $commission = $this->service->calculateReferralCommission($amount);

            $this->assertEquals(
                0.0,
                $commission,
                "Commission for non-positive amount {$amount} should be 0"
            );
        }

        // Проверяем ноль отдельно
        $this->assertEquals(0.0, $this->service->calculateReferralCommission(0));
    }

    /**
     * Property 4.2: Комиссия всегда неотрицательна.
     *
     * @test
     */
    public function property_commission_is_never_negative(): void
    {
        $iterations = 100;

        for ($i = 0; $i < $iterations; $i++) {
            // Генерируем любую сумму
            $amount = (mt_rand(-1000000, 10000000)) / 100;

            $commission = $this->service->calculateReferralCommission($amount);

            $this->assertGreaterThanOrEqual(
                0,
                $commission,
                "Commission should never be negative, got {$commission} for amount {$amount}"
            );
        }
    }

    /**
     * Property 4.3: Комиссия пропорциональна сумме.
     *
     * Если сумма A > суммы B, то комиссия A >= комиссии B.
     *
     * @test
     */
    public function property_commission_is_proportional_to_amount(): void
    {
        $iterations = 100;

        for ($i = 0; $i < $iterations; $i++) {
            $amountA = mt_rand(1, 10000000) / 100;
            $amountB = mt_rand(1, 10000000) / 100;

            $commissionA = $this->service->calculateReferralCommission($amountA);
            $commissionB = $this->service->calculateReferralCommission($amountB);

            if ($amountA > $amountB) {
                $this->assertGreaterThanOrEqual(
                    $commissionB,
                    $commissionA,
                    "Commission for larger amount should be >= commission for smaller amount"
                );
            } elseif ($amountA < $amountB) {
                $this->assertLessThanOrEqual(
                    $commissionB,
                    $commissionA,
                    "Commission for smaller amount should be <= commission for larger amount"
                );
            } else {
                $this->assertEquals(
                    $commissionA,
                    $commissionB,
                    "Equal amounts should give equal commissions"
                );
            }
        }
    }

    /**
     * Property 7: Ограничение срока реферальной программы.
     *
     * Реферальная программа активна только 2 года после регистрации.
     *
     * @test
     */
    public function property_referral_program_expires_after_two_years(): void
    {
        // Создаём пользователя, зарегистрированного 3 года назад
        $oldUser = User::factory()->create([
            'created_at' => now()->subYears(3),
        ]);

        $this->assertFalse(
            $this->service->isReferralProgramActive($oldUser->id),
            "Referral program should be inactive for user registered 3 years ago"
        );

        // Создаём пользователя, зарегистрированного 1 год назад
        $recentUser = User::factory()->create([
            'created_at' => now()->subYear(),
        ]);

        $this->assertTrue(
            $this->service->isReferralProgramActive($recentUser->id),
            "Referral program should be active for user registered 1 year ago"
        );

        // Создаём пользователя, зарегистрированного ровно 2 года назад
        $borderlineUser = User::factory()->create([
            'created_at' => now()->subYears(2)->addDay(),
        ]);

        $this->assertTrue(
            $this->service->isReferralProgramActive($borderlineUser->id),
            "Referral program should be active for user registered just under 2 years ago"
        );
    }

    /**
     * Property 7.1: Несуществующий пользователь - программа неактивна.
     *
     * @test
     */
    public function property_nonexistent_user_has_inactive_program(): void
    {
        $this->assertFalse(
            $this->service->isReferralProgramActive(999999),
            "Referral program should be inactive for non-existent user"
        );
    }
}


/**
 * Property-based тесты для типизации бонусов (AgentBonus model).
 */
class AgentBonusTypingPropertyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Property 6: Корректная типизация бонусов.
     *
     * Бонус с bonus_type = 'referral' должен иметь referral_user_id.
     * Бонус с bonus_type = 'agent' или null не должен иметь referral_user_id.
     *
     * @test
     */
    public function property_referral_bonus_has_referral_user_id(): void
    {
        // Создаём реферера и реферала
        $referrer = User::factory()->create();
        $referral = User::factory()->create(['user_id' => $referrer->id]);

        // Создаём статус бонуса
        $status = BonusStatus::first() ?? BonusStatus::factory()->create();

        // Создаём реферальный бонус
        $referralBonus = AgentBonus::create([
            'agent_id' => $referrer->id,
            'contract_id' => null,
            'order_id' => null,
            'commission_amount' => 100.00,
            'status_id' => $status->id,
            'accrued_at' => now(),
            'bonus_type' => 'referral',
            'referral_user_id' => $referral->id,
        ]);

        $this->assertEquals('referral', $referralBonus->bonus_type);
        $this->assertEquals($referral->id, $referralBonus->referral_user_id);
        $this->assertEquals('Реферальный', $referralBonus->bonus_type_label);

        // Создаём агентский бонус
        $agentBonus = AgentBonus::create([
            'agent_id' => $referrer->id,
            'contract_id' => null,
            'order_id' => null,
            'commission_amount' => 200.00,
            'status_id' => $status->id,
            'accrued_at' => now(),
            'bonus_type' => 'agent',
            'referral_user_id' => null,
        ]);

        $this->assertEquals('agent', $agentBonus->bonus_type);
        $this->assertNull($agentBonus->referral_user_id);
        $this->assertEquals('Агентский', $agentBonus->bonus_type_label);
    }

    /**
     * Property 6.1: Scope фильтрации по типу бонуса работает корректно.
     *
     * @test
     */
    public function property_bonus_type_scopes_filter_correctly(): void
    {
        $user = User::factory()->create();
        $referral = User::factory()->create(['user_id' => $user->id]);
        $status = BonusStatus::first() ?? BonusStatus::factory()->create();

        // Создаём несколько бонусов разных типов
        $iterations = 10;
        $agentCount = 0;
        $referralCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $isReferral = mt_rand(0, 1) === 1;

            AgentBonus::create([
                'agent_id' => $user->id,
                'commission_amount' => mt_rand(100, 10000) / 100,
                'status_id' => $status->id,
                'accrued_at' => now(),
                'bonus_type' => $isReferral ? 'referral' : 'agent',
                'referral_user_id' => $isReferral ? $referral->id : null,
            ]);

            if ($isReferral) {
                $referralCount++;
            } else {
                $agentCount++;
            }
        }

        // Проверяем scope для агентских бонусов
        $agentBonuses = AgentBonus::where('agent_id', $user->id)->agentBonuses()->count();
        $this->assertEquals($agentCount, $agentBonuses);

        // Проверяем scope для реферальных бонусов
        $referralBonuses = AgentBonus::where('agent_id', $user->id)->referralBonuses()->count();
        $this->assertEquals($referralCount, $referralBonuses);
    }

    /**
     * Property 6.2: Связь referralUser работает корректно.
     *
     * @test
     */
    public function property_referral_user_relation_works(): void
    {
        $referrer = User::factory()->create();
        $referral = User::factory()->create(['user_id' => $referrer->id]);
        $status = BonusStatus::first() ?? BonusStatus::factory()->create();

        $bonus = AgentBonus::create([
            'agent_id' => $referrer->id,
            'commission_amount' => 50.00,
            'status_id' => $status->id,
            'accrued_at' => now(),
            'bonus_type' => 'referral',
            'referral_user_id' => $referral->id,
        ]);

        $this->assertNotNull($bonus->referralUser);
        $this->assertEquals($referral->id, $bonus->referralUser->id);
        $this->assertEquals($referral->name, $bonus->referralUser->name);
    }

    /**
     * Property 6.3: Default bonus_type = 'agent'.
     *
     * @test
     */
    public function property_default_bonus_type_label_is_agent(): void
    {
        $user = User::factory()->create();
        $status = BonusStatus::first() ?? BonusStatus::factory()->create();

        // Бонус без явного указания типа
        $bonus = AgentBonus::create([
            'agent_id' => $user->id,
            'commission_amount' => 100.00,
            'status_id' => $status->id,
            'accrued_at' => now(),
        ]);

        // bonus_type_label должен быть "Агентский" по умолчанию
        $this->assertEquals('Агентский', $bonus->bonus_type_label);
    }
}
