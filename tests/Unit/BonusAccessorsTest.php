<?php

namespace Tests\Unit;

use App\Models\Bonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-based tests для accessors модели Bonus.
 *
 * Feature: unified-bonuses-model
 * Property 9: Recipient type label mapping
 * Validates: Requirements 4.9
 */
class BonusAccessorsTest extends TestCase
{
    use RefreshDatabase;

    private BonusStatus $pendingStatus;
    private User $user;
    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pendingStatus = BonusStatus::firstOrCreate(
            ['code' => 'pending'],
            ['name' => 'Ожидание', 'description' => 'Бонус ожидает выплаты']
        );

        $this->user = User::factory()->create();
        $this->contract = Contract::factory()->create([
            'contract_amount' => 100000,
        ]);
    }

    /**
     * @test
     * Property 9: Recipient type label mapping
     *
     * For any bonus record, the recipient_type_label accessor SHALL return:
     * - 'Агент' for recipient_type = 'agent'
     * - 'Куратор' for recipient_type = 'curator'
     * - 'Реферер' for recipient_type = 'referrer'
     */
    public function recipient_type_label_returns_correct_labels(): void
    {
        $expectedLabels = [
            Bonus::RECIPIENT_AGENT => 'Агент',
            Bonus::RECIPIENT_CURATOR => 'Куратор',
            Bonus::RECIPIENT_REFERRER => 'Реферер',
        ];

        // Тестируем 100 итераций для каждого типа
        for ($i = 0; $i < 100; $i++) {
            foreach ($expectedLabels as $recipientType => $expectedLabel) {
                $bonus = Bonus::create([
                    'user_id' => $this->user->id,
                    'contract_id' => $this->contract->id,
                    'commission_amount' => rand(100, 10000),
                    'status_id' => $this->pendingStatus->id,
                    'recipient_type' => $recipientType,
                    'accrued_at' => now(),
                ]);

                $this->assertEquals(
                    $expectedLabel,
                    $bonus->recipient_type_label,
                    "recipient_type_label должен возвращать '{$expectedLabel}' для recipient_type = '{$recipientType}'"
                );

                // Удаляем для следующей итерации
                $bonus->delete();
            }
        }
    }

    /**
     * @test
     * Property 9: Recipient type label mapping - unknown type
     *
     * For any bonus with unknown recipient_type, the accessor SHALL return 'Неизвестно'
     */
    public function recipient_type_label_returns_unknown_for_invalid_type(): void
    {
        $bonus = new Bonus([
            'user_id' => $this->user->id,
            'contract_id' => $this->contract->id,
            'commission_amount' => 1000,
            'status_id' => $this->pendingStatus->id,
            'recipient_type' => 'invalid_type',
            'accrued_at' => now(),
        ]);

        $this->assertEquals('Неизвестно', $bonus->recipient_type_label);
    }

    /**
     * @test
     * Bonus type label accessor returns correct labels
     */
    public function bonus_type_label_returns_correct_labels(): void
    {
        $testCases = [
            ['bonus_type' => 'agent', 'expected' => 'Агентский'],
            ['bonus_type' => 'referral', 'expected' => 'Реферальный'],
            ['bonus_type' => null, 'expected' => 'Агентский'],
        ];

        foreach ($testCases as $testCase) {
            $bonus = new Bonus([
                'user_id' => $this->user->id,
                'contract_id' => $this->contract->id,
                'commission_amount' => 1000,
                'status_id' => $this->pendingStatus->id,
                'recipient_type' => 'agent',
                'bonus_type' => $testCase['bonus_type'],
                'accrued_at' => now(),
            ]);

            $this->assertEquals(
                $testCase['expected'],
                $bonus->bonus_type_label,
                "bonus_type_label должен возвращать '{$testCase['expected']}' для bonus_type = '{$testCase['bonus_type']}'"
            );
        }
    }
}
