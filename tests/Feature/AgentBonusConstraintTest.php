<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Property-based тесты для проверки XOR constraint в таблице agent_bonuses.
 *
 * **Feature: bonus-calculation-system, Property 1: Constraint — ровно один источник бонуса**
 * **Validates: Requirements 1.5**
 */
class AgentBonusConstraintTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **Feature: bonus-calculation-system, Property 1: Constraint — ровно один источник бонуса**
     * **Validates: Requirements 1.5**
     *
     * Property: For any agent_bonus record, exactly one of contract_id or order_id must be not null.
     * Попытка создать запись с обоими NULL должна вызвать ошибку.
     */
    public function test_xor_constraint_rejects_both_null(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('agent_bonuses')->insert([
            'agent_id' => 1,
            'contract_id' => null,
            'order_id' => null,
            'commission_amount' => 1000.00,
            'status_id' => 1,
            'accrued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * **Feature: bonus-calculation-system, Property 1: Constraint — ровно один источник бонуса**
     * **Validates: Requirements 1.5**
     *
     * Property: For any agent_bonus record, exactly one of contract_id or order_id must be not null.
     * Попытка создать запись с обоими NOT NULL должна вызвать ошибку.
     */
    public function test_xor_constraint_rejects_both_not_null(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);


        // Сначала создадим тестовые данные
        $contractId = $this->createTestContract();
        $orderId = $this->createTestOrder();

        DB::table('agent_bonuses')->insert([
            'agent_id' => 1,
            'contract_id' => $contractId,
            'order_id' => $orderId,
            'commission_amount' => 1000.00,
            'status_id' => 1,
            'accrued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * **Feature: bonus-calculation-system, Property 1: Constraint — ровно один источник бонуса**
     * **Validates: Requirements 1.5**
     *
     * Property: Запись с только contract_id (order_id = null) должна быть валидной.
     *
     * @dataProvider validContractOnlyProvider
     */
    public function test_xor_constraint_accepts_contract_only(float $commissionAmount): void
    {
        $contractId = $this->createTestContract();

        $result = DB::table('agent_bonuses')->insert([
            'agent_id' => 1,
            'contract_id' => $contractId,
            'order_id' => null,
            'commission_amount' => $commissionAmount,
            'status_id' => 1,
            'accrued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($result);
    }

    /**
     * **Feature: bonus-calculation-system, Property 1: Constraint — ровно один источник бонуса**
     * **Validates: Requirements 1.5**
     *
     * Property: Запись с только order_id (contract_id = null) должна быть валидной.
     *
     * @dataProvider validOrderOnlyProvider
     */
    public function test_xor_constraint_accepts_order_only(float $commissionAmount): void
    {
        $orderId = $this->createTestOrder();

        $result = DB::table('agent_bonuses')->insert([
            'agent_id' => 1,
            'contract_id' => null,
            'order_id' => $orderId,
            'commission_amount' => $commissionAmount,
            'status_id' => 1,
            'accrued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($result);
    }


    /**
     * Генератор данных для property-based теста с contract_id.
     * Генерирует 100 случайных сумм комиссий.
     */
    public static function validContractOnlyProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $amount = mt_rand(1, 10000000) / 100;
            $testCases["commission_{$i}"] = [$amount];
        }
        return $testCases;
    }

    /**
     * Генератор данных для property-based теста с order_id.
     * Генерирует 100 случайных сумм комиссий.
     */
    public static function validOrderOnlyProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $amount = mt_rand(1, 10000000) / 100;
            $testCases["commission_{$i}"] = [$amount];
        }
        return $testCases;
    }

    /**
     * Создаёт тестовый договор и возвращает его ID.
     */
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

    /**
     * Создаёт тестовую закупку и возвращает её ID.
     */
    private function createTestOrder(): string
    {
        $id = \Illuminate\Support\Str::ulid()->toString();
        $projectId = $this->createTestProject();
        $companyId = $this->createTestCompany();

        DB::table('orders')->insert([
            'id' => $id,
            'value' => 'Test Order',
            'company_id' => $companyId,
            'project_id' => $projectId,
            'order_number' => 'ORD-' . $id,
            'is_active' => true,
            'partner_payment_status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }


    /**
     * Создаёт тестовый проект и возвращает его ID.
     */
    private function createTestProject(): string
    {
        $id = \Illuminate\Support\Str::ulid()->toString();

        // Проверяем, существует ли уже проект
        $existing = DB::table('projects')->where('id', $id)->first();
        if ($existing) {
            return $existing->id;
        }

        DB::table('projects')->insert([
            'id' => $id,
            'name' => 'Test Project ' . $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Создаёт тестовую компанию и возвращает её ID.
     */
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
