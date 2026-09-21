<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ContractFactory extends Factory
{
    protected $model = \App\Models\Contract::class;

    public function definition(): array
    {
        return ['project_id' => ProjectFactory::new(), 'company_id' => CompanyFactory::new(), 'contract_date' => now(), 'planned_completion_date' => now()->addMonth(), 'contract_amount' => 100000, 'agent_percentage' => 3, 'curator_percentage' => 2, 'is_active' => true, 'status_id' => \Illuminate\Support\Facades\DB::table('contract_statuses')->where('slug', 'signed')->value('id')];
    }
}
