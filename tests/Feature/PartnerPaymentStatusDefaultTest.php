<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Property-based тесты для проверки дефолтных статусов оплаты партнёром.
 *
 * **Feature: bonus-calculation-system, Property 2: Дефолтный статус оплаты для договоров**
 * **Feature: bonus-calculation-system, Property 3: Дефолтный статус оплаты для закупок**
 * **Validates: Requirements 3.5, 3.6**
 */
class PartnerPaymentStatusDefaultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **Feature: bonus-calculation-system, Property 2: Дефолтный статус оплаты для договоров**
     * **Validates: Requirements 3.5**
     *
     * Property: For any newly created contract, partner_payment_status must be set to 'pending' (id=1).
     *
     * @dataProvider contractDataProvider
     */
    public function test_new_contract_has_pending_partner_payment_status(
        float $agentPercentage,
        float $curatorPercentage
    ): void {
        $contractId = \Illuminate\Support\Str::ulid()->toString();
        $projectId = $this->createTestProject();
        $companyId = $this->createTestCompany();

        DB::table('contracts')->insert([
            'id' => $contractId,
            'project_id' => $projectId,
            'company_id' => $companyId,
            'contract_number' => 'CONTRACT-' . $contractId,
            'contract_date' => now(),
            'planned_completion_date' => now()->addMonths(3),
            'agent_percentage' => $agentPercentage,
            'curator_percentage' => $curatorPercentage,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contract = DB::table('contracts')->where('id', $contractId)->first();


        // Проверяем, что статус по умолчанию = 1 (pending)
        $this->assertEquals(
            1,
            $contract->partner_payment_status_id,
            "New contract should have partner_payment_status_id = 1 (pending)"
        );

        // Проверяем, что статус действительно 'pending'
        $status = DB::table('partner_payment_statuses')
            ->where('id', $contract->partner_payment_status_id)
            ->first();
        $this->assertEquals('pending', $status->code);
    }

    /**
     * **Feature: bonus-calculation-system, Property 3: Дефолтный статус оплаты для закупок**
     * **Validates: Requirements 3.6**
     *
     * Property: For any newly created order, partner_payment_status must be set to 'pending' (id=1).
     *
     * @dataProvider orderDataProvider
     */
    public function test_new_order_has_pending_partner_payment_status(
        string $value,
        bool $isUrgent
    ): void {
        $orderId = \Illuminate\Support\Str::ulid()->toString();
        $projectId = $this->createTestProject();
        $companyId = $this->createTestCompany();

        DB::table('orders')->insert([
            'id' => $orderId,
            'value' => $value,
            'company_id' => $companyId,
            'project_id' => $projectId,
            'order_number' => 'ORDER-' . $orderId,
            'is_active' => true,
            'is_urgent' => $isUrgent,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = DB::table('orders')->where('id', $orderId)->first();

        // Проверяем, что статус по умолчанию = 1 (pending)
        $this->assertEquals(
            1,
            $order->partner_payment_status_id,
            "New order should have partner_payment_status_id = 1 (pending)"
        );

        // Проверяем, что статус действительно 'pending'
        $status = DB::table('partner_payment_statuses')
            ->where('id', $order->partner_payment_status_id)
            ->first();
        $this->assertEquals('pending', $status->code);
    }


    /**
     * Генератор данных для property-based теста договоров.
     * Генерирует 100 комбинаций процентов.
     */
    public static function contractDataProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $agentPercentage = mt_rand(0, 10000) / 100;
            $curatorPercentage = mt_rand(0, 10000) / 100;
            $testCases["contract_{$i}"] = [$agentPercentage, $curatorPercentage];
        }
        return $testCases;
    }

    /**
     * Генератор данных для property-based теста закупок.
     * Генерирует 100 комбинаций значений.
     */
    public static function orderDataProvider(): array
    {
        $testCases = [];
        $values = ['Материалы', 'Оборудование', 'Услуги', 'Комплектующие', 'Расходники'];

        for ($i = 0; $i < 100; $i++) {
            $value = $values[array_rand($values)] . ' #' . $i;
            $isUrgent = (bool) mt_rand(0, 1);
            $testCases["order_{$i}"] = [$value, $isUrgent];
        }
        return $testCases;
    }

    /**
     * Создаёт тестовый проект и возвращает его ID.
     */
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
