<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = \App\Models\Order::class;

    public function definition(): array
    {
        return ['project_id' => ProjectFactory::new(), 'company_id' => CompanyFactory::new(), 'order_amount' => 50000, 'agent_percentage' => 5, 'curator_percentage' => 5, 'is_active' => true, 'status_id' => \Illuminate\Support\Facades\DB::table('order_statuses')->where('slug', 'formed')->value('id')];
    }
}
