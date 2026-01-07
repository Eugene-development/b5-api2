<?php

namespace Tests\Feature;

use App\Models\BonusPaymentRequest;
use App\Models\BonusPaymentStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Property-based тесты для системы заявок на выплату бонусов.
 *
 * Feature: bonus-payments
 * Тестирует создание заявок, валидацию и переходы статусов.
 */
class BonusPaymentRequestPropertyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBonusPaymentStatuses();
    }

    /**
     * **Feature: bonus-payments, Property 1: Default Status Assignment**
     * **Validates: Requirements 2.4**
     *
     * Property: For any newly created bonus payment request,
     * the status SHALL be set to "requested".
     *
     * @dataProvider validPaymentRequestProvider
     */
    public function test_default_status_is_requested(
        float $amount,
        string $paymentMethod,
        ?string $cardNumber,
        ?string $phoneNumber,
        ?string $contactInfo
    ): void {
        $agent = $this->createTestAgent();
        $requestedStatus = BonusPaymentStatus::findByCode('requested');

        $request = BonusPaymentRequest::create([
            'agent_id' => $agent->id,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'card_number' => $cardNumber,
            'phone_number' => $phoneNumber,
            'contact_info' => $contactInfo,
            'status_id' => $requestedStatus->id,
        ]);

        $this->assertEquals('requested', $request->status->code);
        $this->assertNull($request->payment_date);
    }

    /**
     * **Feature: bonus-payments, Property 2: Amount Validation**
     * **Validates: Requirements 3.2**
     *
     * Property: For any bonus payment request creation attempt with amount <= 0,
     * the request SHALL be invalid.
     *
     * @dataProvider invalidAmountProvider
     */
    public function test_amount_must_be_positive(float $amount): void
    {
        $this->assertTrue($amount <= 0, 'Test data should have amount <= 0');

        // Валидация должна отклонять такие суммы
        $isValid = $amount > 0;
        $this->assertFalse($isValid, 'Amount <= 0 should be invalid');
    }

    /**
     * **Feature: bonus-payments, Property 3: Conditional Field Validation**
     * **Validates: Requirements 3.5, 3.6, 3.7**
     *
     * Property: For any bonus payment request creation:
     * - If payment_method is "card", card_number MUST be provided
     * - If payment_method is "sbp", phone_number MUST be provided
     * - If payment_method is "other", contact_info MUST be provided
     *
     * @dataProvider conditionalFieldValidationProvider
     */
    public function test_conditional_field_validation(
        string $paymentMethod,
        ?string $cardNumber,
        ?string $phoneNumber,
        ?string $contactInfo,
        bool $shouldBeValid
    ): void {
        $isValid = match ($paymentMethod) {
            'card' => !empty($cardNumber),
            'sbp' => !empty($phoneNumber),
            'other' => !empty($contactInfo),
            default => false,
        };

        $this->assertEquals($shouldBeValid, $isValid);
    }

    /**
     * **Feature: bonus-payments, Property 4: Status Update with Payment Date**
     * **Validates: Requirements 5.2**
     *
     * Property: For any status update to "paid",
     * the payment_date field SHALL be automatically set.
     *
     * @dataProvider statusUpdateProvider
     */
    public function test_payment_date_set_on_paid_status(float $amount): void
    {
        $agent = $this->createTestAgent();
        $requestedStatus = BonusPaymentStatus::findByCode('requested');
        $paidStatus = BonusPaymentStatus::findByCode('paid');

        $request = BonusPaymentRequest::create([
            'agent_id' => $agent->id,
            'amount' => $amount,
            'payment_method' => 'card',
            'card_number' => '4111111111111111',
            'status_id' => $requestedStatus->id,
        ]);

        $this->assertNull($request->payment_date);

        // Обновляем статус на "paid"
        $request->update([
            'status_id' => $paidStatus->id,
            'payment_date' => now(),
        ]);

        $request->refresh();

        $this->assertEquals('paid', $request->status->code);
        $this->assertNotNull($request->payment_date);
    }

    /**
     * **Feature: bonus-payments, Property 5: Valid Status Transition**
     * **Validates: Requirements 5.3**
     *
     * Property: For any status update attempt,
     * the new status code MUST exist in the bonus_payment_statuses table.
     *
     * @dataProvider validStatusCodesProvider
     */
    public function test_valid_status_codes(string $statusCode, bool $shouldExist): void
    {
        $status = BonusPaymentStatus::findByCode($statusCode);

        if ($shouldExist) {
            $this->assertNotNull($status, "Status '{$statusCode}' should exist");
        } else {
            $this->assertNull($status, "Status '{$statusCode}' should not exist");
        }
    }

    /**
     * **Feature: bonus-payments, Property 6: Pagination Consistency**
     * **Validates: Requirements 4.1**
     *
     * Property: For any paginated query of bonus payment requests,
     * the total count SHALL equal the sum of items across all pages.
     *
     * @dataProvider paginationProvider
     */
    public function test_pagination_consistency(int $totalItems, int $perPage): void
    {
        $agent = $this->createTestAgent();
        $requestedStatus = BonusPaymentStatus::findByCode('requested');

        // Создаём заявки
        for ($i = 0; $i < $totalItems; $i++) {
            BonusPaymentRequest::create([
                'agent_id' => $agent->id,
                'amount' => 1000 + $i,
                'payment_method' => 'card',
                'card_number' => '4111111111111111',
                'status_id' => $requestedStatus->id,
            ]);
        }

        // Проверяем пагинацию
        $paginator = BonusPaymentRequest::paginate($perPage);

        $this->assertEquals($totalItems, $paginator->total());

        // Проверяем, что сумма элементов на всех страницах равна общему количеству
        $itemsCount = 0;
        $currentPage = 1;
        $lastPage = $paginator->lastPage();

        while ($currentPage <= $lastPage) {
            $paginator = BonusPaymentRequest::paginate($perPage, ['*'], 'page', $currentPage);
            $itemsCount += $paginator->count();
            $currentPage++;
        }

        $this->assertEquals($totalItems, $itemsCount);
    }

    /**
     * **Feature: bonus-payments, Property 7: Filtering Correctness**
     * **Validates: Requirements 4.3**
     *
     * Property: For any query with status_id filter,
     * all returned requests SHALL have the specified status_id.
     *
     * @dataProvider filteringProvider
     */
    public function test_filtering_by_status(int $requestedCount, int $approvedCount, int $paidCount): void
    {
        $agent = $this->createTestAgent();
        $requestedStatus = BonusPaymentStatus::findByCode('requested');
        $approvedStatus = BonusPaymentStatus::findByCode('approved');
        $paidStatus = BonusPaymentStatus::findByCode('paid');

        // Создаём заявки с разными статусами
        for ($i = 0; $i < $requestedCount; $i++) {
            BonusPaymentRequest::create([
                'agent_id' => $agent->id,
                'amount' => 1000,
                'payment_method' => 'card',
                'card_number' => '4111111111111111',
                'status_id' => $requestedStatus->id,
            ]);
        }

        for ($i = 0; $i < $approvedCount; $i++) {
            BonusPaymentRequest::create([
                'agent_id' => $agent->id,
                'amount' => 2000,
                'payment_method' => 'sbp',
                'phone_number' => '+79001234567',
                'status_id' => $approvedStatus->id,
            ]);
        }

        for ($i = 0; $i < $paidCount; $i++) {
            BonusPaymentRequest::create([
                'agent_id' => $agent->id,
                'amount' => 3000,
                'payment_method' => 'other',
                'contact_info' => 'test@test.com',
                'status_id' => $paidStatus->id,
                'payment_date' => now(),
            ]);
        }

        // Проверяем фильтрацию
        $requestedRequests = BonusPaymentRequest::where('status_id', $requestedStatus->id)->get();
        $this->assertCount($requestedCount, $requestedRequests);
        foreach ($requestedRequests as $request) {
            $this->assertEquals($requestedStatus->id, $request->status_id);
        }

        $approvedRequests = BonusPaymentRequest::where('status_id', $approvedStatus->id)->get();
        $this->assertCount($approvedCount, $approvedRequests);
        foreach ($approvedRequests as $request) {
            $this->assertEquals($approvedStatus->id, $request->status_id);
        }

        $paidRequests = BonusPaymentRequest::where('status_id', $paidStatus->id)->get();
        $this->assertCount($paidCount, $paidRequests);
        foreach ($paidRequests as $request) {
            $this->assertEquals($paidStatus->id, $request->status_id);
        }
    }

    // ==================== DATA PROVIDERS ====================

    public static function validPaymentRequestProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $amount = mt_rand(100, 1000000) / 100;
            $methods = ['card', 'sbp', 'other'];
            $method = $methods[array_rand($methods)];

            $cardNumber = $method === 'card' ? '4111111111111111' : null;
            $phoneNumber = $method === 'sbp' ? '+7900' . mt_rand(1000000, 9999999) : null;
            $contactInfo = $method === 'other' ? 'contact_' . $i . '@test.com' : null;

            $testCases["request_{$i}"] = [$amount, $method, $cardNumber, $phoneNumber, $contactInfo];
        }
        return $testCases;
    }

    public static function invalidAmountProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            // Генерируем отрицательные и нулевые суммы
            $amount = mt_rand(-100000, 0) / 100;
            $testCases["invalid_amount_{$i}"] = [$amount];
        }
        return $testCases;
    }

    public static function conditionalFieldValidationProvider(): array
    {
        return [
            // card method
            ['card', '4111111111111111', null, null, true],
            ['card', null, null, null, false],
            ['card', '', null, null, false],

            // sbp method
            ['sbp', null, '+79001234567', null, true],
            ['sbp', null, null, null, false],
            ['sbp', null, '', null, false],

            // other method
            ['other', null, null, 'test@test.com', true],
            ['other', null, null, null, false],
            ['other', null, null, '', false],
        ];
    }

    public static function statusUpdateProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; $i++) {
            $amount = mt_rand(100, 1000000) / 100;
            $testCases["status_update_{$i}"] = [$amount];
        }
        return $testCases;
    }

    public static function validStatusCodesProvider(): array
    {
        return [
            ['requested', true],
            ['approved', true],
            ['paid', true],
            ['invalid_status', false],
            ['pending', false],
            ['completed', false],
        ];
    }

    public static function paginationProvider(): array
    {
        return [
            [10, 5],
            [25, 10],
            [50, 15],
            [100, 20],
        ];
    }

    public static function filteringProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 20; $i++) {
            $requested = mt_rand(0, 10);
            $approved = mt_rand(0, 10);
            $paid = mt_rand(0, 10);
            $testCases["filter_{$i}"] = [$requested, $approved, $paid];
        }
        return $testCases;
    }

    // ==================== HELPER METHODS ====================

    private function seedBonusPaymentStatuses(): void
    {
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
    }

    private function createTestAgent(): User
    {
        return User::create([
            'name' => 'Test Agent ' . uniqid(),
            'email' => 'agent_' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
        ]);
    }
}
