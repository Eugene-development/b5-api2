<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = \App\Models\Company::class;

    public function definition(): array
    {
        return ['id' => (string) \Illuminate\Support\Str::ulid(), 'name' => fake()->company(), 'status_id' => \Illuminate\Support\Facades\DB::table('company_statuses')->value('id')];
    }
}
