<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Order;
use App\Models\Project;
use App\Models\Company;
use App\Models\User;
use App\Models\Client;
use App\Models\ProjectStatus;
use App\Models\CompanyStatus;
use App\Services\BonusCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for bonus calculation system
 * Tests end-to-end flows for contracts and orders
 *
 * **Feature: bonus-calculation-system**
 * **Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6**
 */
class BonusIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Project $project;
    protected Company $company;
    protected BonusCalculationService $bonusService;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations from b5-db-2 project
        $this->artisan('migrate', [
            '--path' => '../b5-db-2/database/migrations',
            '--realpath' => true,
        ]);

        $this->bonusService = app(BonusCalculationService::class);

        // Create test user
        $this->user = User::factory()->create();

        // Create client for project
        $client = Client::create([
            'name' => 'Test Client',
            'is_active' => true,
        ]);

        // Create project status
        $projectStatus = ProjectStatus::create([
            'name' => 'Active',
            'is_active' => true,
        ]);

        // Create test project
        $this->project = Project::create([
            'value' => 'Test Project',
            'description' => 'Test Description',
            'contract_number' => 'PRJ-001',
            'contract_date' => '2024-01-01',
            'planned_completion_date' => '2024-12-31',
            'contract_amount' => 100000.00,
            'agent_percentage' => 10.00,
            'is_active' => true,
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'status_id' => $projectStatus->id,
        ]);

        // Create company status
        $companyStatus = CompanyStatus::create([
            'name' => 'Active',
            'is_active' => true,
        ]);

        // Create test company
        $this->company = Company::create([
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'inn' => '1234567890',
            'ban' => false,
            'is_active' => true,
            'region' => 'Test Region',
            'status_id' => $companyStatus->id,
        ]);
    }

    /**
     * Test: Create contract with amount → verify bonus calculation
     * **Feature: bonus-calculation-system, Property 1: Bonus Calculation Formula**
     * **Validates: Requirements 3.1, 3.2**
     *
     * @test
     */
    public function contract_bonus_is_calculated_on_creation()
    {
        $contract = Contract::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'contract_number' => 'CNT-001',
            'contract_date' => '2024-01-15',
            'contract_amount' => 50000.00,
            'agent_percentage' => 3.00,
            'curator_percentage' => 2.00,
            'is_active' => true,
        ]);

        // Refresh to get calculated bonuses
        $contract->refresh();

        // Verify bonuses are calculated correctly
        // agent_bonus = 50000 * 3 / 100 = 1500
        $this->assertEquals(1500.00, $contract->agent_bonus);
        // curator_bonus = 50000 * 2 / 100 = 1000
        $this->assertEquals(1000.00, $contract->curator_bonus);
    }

    /**
     * Test: Create order with amount → verify bonus calculation
     * **Feature: bonus-calculation-system, Property 1: Bonus Calculation Formula**
     * **Validates: Requirements 3.3, 3.4**
     *
     * @test
     */
    public function order_bonus_is_calculated_on_creation()
    {
        $order = Order::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'order_number' => 'ORD-001',
            'value' => 'Test Order',
            'order_amount' => 25000.00,
            'agent_percentage' => 5.00,
            'curator_percentage' => 5.00,
            'is_active' => true,
        ]);

        // Refresh to get calculated bonuses
        $order->refresh();

        // Verify bonuses are calculated correctly
        // agent_bonus = 25000 * 5 / 100 = 1250
        $this->assertEquals(1250.00, $order->agent_bonus);
        // curator_bonus = 25000 * 5 / 100 = 1250
        $this->assertEquals(1250.00, $order->curator_bonus);
    }

    /**
     * Test: Deactivate contract → verify bonus becomes 0
     * **Feature: bonus-calculation-system, Property 2: Zero Bonus for Inactive Items**
     * **Validates: Requirements 3.5**
     *
     * @test
     */
    public function contract_bonus_becomes_zero_when_deactivated()
    {
        $contract = Contract::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'contract_number' => 'CNT-002',
            'contract_date' => '2024-01-15',
            'contract_amount' => 50000.00,
            'agent_percentage' => 3.00,
            'curator_percentage' => 2.00,
            'is_active' => true,
        ]);

        // Verify initial bonuses
        $contract->refresh();
        $this->assertEquals(1500.00, $contract->agent_bonus);
        $this->assertEquals(1000.00, $contract->curator_bonus);

        // Deactivate contract
        $contract->is_active = false;
        $contract->save();
        $contract->refresh();

        // Verify bonuses are now zero
        $this->assertEquals(0.00, $contract->agent_bonus);
        $this->assertEquals(0.00, $contract->curator_bonus);
    }

    /**
     * Test: Deactivate order → verify bonus becomes 0
     * **Feature: bonus-calculation-system, Property 2: Zero Bonus for Inactive Items**
     * **Validates: Requirements 3.6**
     *
     * @test
     */
    public function order_bonus_becomes_zero_when_deactivated()
    {
        $order = Order::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'order_number' => 'ORD-002',
            'value' => 'Test Order',
            'order_amount' => 25000.00,
            'agent_percentage' => 5.00,
            'curator_percentage' => 5.00,
            'is_active' => true,
        ]);

        // Verify initial bonuses
        $order->refresh();
        $this->assertEquals(1250.00, $order->agent_bonus);
        $this->assertEquals(1250.00, $order->curator_bonus);

        // Deactivate order
        $order->is_active = false;
        $order->save();
        $order->refresh();

        // Verify bonuses are now zero
        $this->assertEquals(0.00, $order->agent_bonus);
        $this->assertEquals(0.00, $order->curator_bonus);
    }

    /**
     * Test: Project bonus aggregation with multiple contracts and orders
     * **Feature: bonus-calculation-system, Property 8: Project Bonus Aggregation**
     * **Validates: Requirements 4.4, 5.4, 6.4**
     *
     * @test
     */
    public function project_aggregates_bonuses_from_contracts_and_orders()
    {
        // Create multiple contracts
        Contract::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'contract_number' => 'CNT-003',
            'contract_date' => '2024-01-15',
            'contract_amount' => 100000.00,
            'agent_percentage' => 3.00,
            'curator_percentage' => 2.00,
            'is_active' => true,
        ]);

        Contract::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'contract_number' => 'CNT-004',
            'contract_date' => '2024-02-15',
            'contract_amount' => 50000.00,
            'agent_percentage' => 3.00,
            'curator_percentage' => 2.00,
            'is_active' => true,
        ]);

        // Create multiple orders
        Order::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'order_number' => 'ORD-003',
            'value' => 'Test Order 1',
            'order_amount' => 20000.00,
            'agent_percentage' => 5.00,
            'curator_percentage' => 5.00,
            'is_active' => true,
        ]);

        Order::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'order_number' => 'ORD-004',
            'value' => 'Test Order 2',
            'order_amount' => 30000.00,
            'agent_percentage' => 5.00,
            'curator_percentage' => 5.00,
            'is_active' => true,
        ]);

        // Get project bonus summary
        $summary = $this->bonusService->getProjectBonusSummary($this->project->id);

        // Expected totals:
        // Contracts: (100000 * 3/100) + (50000 * 3/100) = 3000 + 1500 = 4500 agent
        //            (100000 * 2/100) + (50000 * 2/100) = 2000 + 1000 = 3000 curator
        // Orders:    (20000 * 5/100) + (30000 * 5/100) = 1000 + 1500 = 2500 agent
        //            (20000 * 5/100) + (30000 * 5/100) = 1000 + 1500 = 2500 curator
        // Total:     4500 + 2500 = 7000 agent, 3000 + 2500 = 5500 curator

        $this->assertEquals(7000.00, $summary['totalAgentBonus']);
        $this->assertEquals(5500.00, $summary['totalCuratorBonus']);
        $this->assertCount(2, $summary['contracts']);
        $this->assertCount(2, $summary['orders']);
    }

    /**
     * Test: Order uses default percentages when not specified
     * **Feature: bonus-calculation-system, Property 6: Default Percentages for Orders**
     * **Validates: Requirements 1.2**
     *
     * @test
     */
    public function order_uses_default_percentages()
    {
        $order = new Order([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'order_number' => 'ORD-005',
            'value' => 'Test Order',
            'order_amount' => 10000.00,
            'is_active' => true,
        ]);

        // Check default percentages before save
        $this->assertEquals(5.00, $order->agent_percentage);
        $this->assertEquals(5.00, $order->curator_percentage);

        $order->save();
        $order->refresh();

        // Verify bonuses calculated with default percentages
        // agent_bonus = 10000 * 5 / 100 = 500
        $this->assertEquals(500.00, $order->agent_bonus);
        // curator_bonus = 10000 * 5 / 100 = 500
        $this->assertEquals(500.00, $order->curator_bonus);
    }
}
