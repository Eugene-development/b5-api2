<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Project;
use App\Models\Company;
use App\Models\User;
use App\Models\Client;
use App\Models\ProjectStatus;
use App\Models\CompanyStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Project $project;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations from b5-db-2 project
        $this->artisan('migrate', [
            '--path' => '../b5-db-2/database/migrations',
            '--realpath' => true,
        ]);

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

    /** @test */
    public function it_can_create_contract_with_valid_data()
    {
        $response = $this->graphQL('
            mutation CreateContract($input: CreateContractInput!) {
                createContract(input: $input) {
                    id
                    project_id
                    company_id
                    contract_number
                    contract_date
                    planned_completion_date
                    agent_percentage
                    curator_percentage
                    is_active
                }
            }
        ', [
            'input' => [
                'project_id' => $this->project->id,
                'company_id' => $this->company->id,
                'contract_number' => 'CNT-001',
                'contract_date' => '2024-01-15',
                'planned_completion_date' => '2024-06-30',
                'agent_percentage' => 15.5,
                'curator_percentage' => 10.0,
                'is_active' => true,
            ],
        ]);

        $response->assertJson([
            'data' => [
                'createContract' => [
                    'project_id' => $this->project->id,
                    'company_id' => $this->company->id,
                    'contract_number' => 'CNT-001',
                    'contract_date' => '2024-01-15',
                    'planned_completion_date' => '2024-06-30',
                    'agent_percentage' => 15.5,
                    'curator_percentage' => 10.0,
                    'is_active' => true,
                ],
            ],
        ]);

        $this->assertDatabaseHas('contracts', [
            'contract_number' => 'CNT-001',
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
        ]);
    }

    /** @test */
    public function it_fails_to_create_contract_with_invalid_project_id()
    {
        $response = $this->graphQL('
            mutation CreateContract($input: CreateContractInput!) {
                createContract(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'project_id' => 'invalid-id',
                'company_id' => $this->company->id,
                'contract_date' => '2024-01-15',
                'planned_completion_date' => '2024-06-30',
                'agent_percentage' => 15.0,
                'curator_percentage' => 10.0,
            ],
        ]);

        $response->assertGraphQLErrorMessage('The selected input.project_id is invalid.');
    }

    /** @test */
    public function it_fails_to_create_contract_with_invalid_company_id()
    {
        $response = $this->graphQL('
            mutation CreateContract($input: CreateContractInput!) {
                createContract(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'project_id' => $this->project->id,
                'company_id' => 'invalid-id',
                'contract_date' => '2024-01-15',
                'planned_completion_date' => '2024-06-30',
                'agent_percentage' => 15.0,
                'curator_percentage' => 10.0,
            ],
        ]);

        $response->assertGraphQLErrorMessage('The selected input.company_id is invalid.');
    }

    /** @test */
    public function it_fails_to_create_contract_with_invalid_percentage_values()
    {
        $response = $this->graphQL('
            mutation CreateContract($input: CreateContractInput!) {
                createContract(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'project_id' => $this->project->id,
                'company_id' => $this->company->id,
                'contract_date' => '2024-01-15',
                'planned_completion_date' => '2024-06-30',
                'agent_percentage' => 150.0, // Invalid: > 100
                'curator_percentage' => 10.0,
            ],
        ]);

        $response->assertGraphQLValidationError('input.agent_percentage', 'The input.agent_percentage field must not be greater than 100.');
    }

    /** @test */
    public function it_fails_to_create_contract_with_planned_date_before_contract_date()
    {
        $response = $this->graphQL('
            mutation CreateContract($input: CreateContractInput!) {
                createContract(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'project_id' => $this->project->id,
                'company_id' => $this->company->id,
                'contract_date' => '2024-06-30',
                'planned_completion_date' => '2024-01-15', // Before contract_date
                'agent_percentage' => 15.0,
                'curator_percentage' => 10.0,
            ],
        ]);

        $response->assertGraphQLValidationError('input.planned_completion_date', 'The input.planned_completion_date field must be a date after or equal to input.contract_date.');
    }

    /** @test */
    public function it_can_update_contract()
    {
        $contract = Contract::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'contract_number' => 'CNT-002',
            'contract_date' => '2024-01-15',
            'planned_completion_date' => '2024-06-30',
            'agent_percentage' => 15.0,
            'curator_percentage' => 10.0,
            'is_active' => true,
        ]);

        $response = $this->graphQL('
            mutation UpdateContract($input: UpdateContractInput!) {
                updateContract(input: $input) {
                    id
                    contract_number
                    agent_percentage
                    is_active
                }
            }
        ', [
            'input' => [
                'id' => $contract->id,
                'contract_number' => 'CNT-002-UPDATED',
                'agent_percentage' => 20.0,
                'is_active' => false,
            ],
        ]);

        $response->assertJson([
            'data' => [
                'updateContract' => [
                    'id' => $contract->id,
                    'contract_number' => 'CNT-002-UPDATED',
                    'agent_percentage' => 20.0,
                    'is_active' => false,
                ],
            ],
        ]);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'contract_number' => 'CNT-002-UPDATED',
            'agent_percentage' => 20.0,
            'is_active' => false,
        ]);
    }

    /** @test */
    public function it_can_delete_contract()
    {
        $contract = Contract::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'contract_number' => 'CNT-003',
            'contract_date' => '2024-01-15',
            'planned_completion_date' => '2024-06-30',
            'agent_percentage' => 15.0,
            'curator_percentage' => 10.0,
            'is_active' => true,
        ]);

        $response = $this->graphQL('
            mutation DeleteContract($id: ID!) {
                deleteContract(id: $id) {
                    id
                }
            }
        ', [
            'id' => $contract->id,
        ]);

        $response->assertJson([
            'data' => [
                'deleteContract' => [
                    'id' => $contract->id,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('contracts', [
            'id' => $contract->id,
        ]);
    }

    /** @test */
    public function it_can_view_contract_details()
    {
        $contract = Contract::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'contract_number' => 'CNT-004',
            'contract_date' => '2024-01-15',
            'planned_completion_date' => '2024-06-30',
            'actual_completion_date' => '2024-06-15',
            'agent_percentage' => 15.0,
            'curator_percentage' => 10.0,
            'is_active' => true,
        ]);

        $response = $this->graphQL('
            query GetContract($id: ID!) {
                contract(id: $id) {
                    id
                    project_id
                    company_id
                    contract_number
                    contract_date
                    planned_completion_date
                    actual_completion_date
                    agent_percentage
                    curator_percentage
                    is_active
                    project {
                        id
                        value
                    }
                    company {
                        id
                        name
                    }
                }
            }
        ', [
            'id' => $contract->id,
        ]);

        $response->assertJson([
            'data' => [
                'contract' => [
                    'id' => $contract->id,
                    'contract_number' => 'CNT-004',
                    'project' => [
                        'id' => $this->project->id,
                        'value' => 'Test Project',
                    ],
                    'company' => [
                        'id' => $this->company->id,
                        'name' => 'Test Company',
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function it_can_get_contracts_by_project()
    {
        Contract::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'contract_number' => 'CNT-005',
            'contract_date' => '2024-01-15',
            'planned_completion_date' => '2024-06-30',
            'agent_percentage' => 15.0,
            'curator_percentage' => 10.0,
            'is_active' => true,
        ]);

        Contract::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'contract_number' => 'CNT-006',
            'contract_date' => '2024-02-01',
            'planned_completion_date' => '2024-07-31',
            'agent_percentage' => 12.0,
            'curator_percentage' => 8.0,
            'is_active' => true,
        ]);

        $response = $this->graphQL('
            query GetContractsByProject($project_id: ID!) {
                contractsByProject(project_id: $project_id) {
                    id
                    contract_number
                    project_id
                }
            }
        ', [
            'project_id' => $this->project->id,
        ]);

        $response->assertJsonCount(2, 'data.contractsByProject');
        $response->assertJsonFragment(['contract_number' => 'CNT-005']);
        $response->assertJsonFragment(['contract_number' => 'CNT-006']);
    }

    /** @test */
    public function it_can_get_contracts_by_company()
    {
        Contract::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'contract_number' => 'CNT-007',
            'contract_date' => '2024-01-15',
            'planned_completion_date' => '2024-06-30',
            'agent_percentage' => 15.0,
            'curator_percentage' => 10.0,
            'is_active' => true,
        ]);

        $response = $this->graphQL('
            query GetContractsByCompany($company_id: ID!) {
                contractsByCompany(company_id: $company_id) {
                    id
                    contract_number
                    company_id
                }
            }
        ', [
            'company_id' => $this->company->id,
        ]);

        $response->assertJsonCount(1, 'data.contractsByCompany');
        $response->assertJsonFragment(['contract_number' => 'CNT-007']);
    }

    /** @test */
    public function it_enforces_unique_contract_number()
    {
        Contract::create([
            'project_id' => $this->project->id,
            'company_id' => $this->company->id,
            'contract_number' => 'CNT-UNIQUE',
            'contract_date' => '2024-01-15',
            'planned_completion_date' => '2024-06-30',
            'agent_percentage' => 15.0,
            'curator_percentage' => 10.0,
            'is_active' => true,
        ]);

        $response = $this->graphQL('
            mutation CreateContract($input: CreateContractInput!) {
                createContract(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'project_id' => $this->project->id,
                'company_id' => $this->company->id,
                'contract_number' => 'CNT-UNIQUE', // Duplicate
                'contract_date' => '2024-02-01',
                'planned_completion_date' => '2024-07-31',
                'agent_percentage' => 12.0,
                'curator_percentage' => 8.0,
            ],
        ]);

        $response->assertGraphQLValidationError('input.contract_number', 'The input.contract_number has already been taken.');
    }
}
