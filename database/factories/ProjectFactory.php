<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = \App\Models\Project::class;

    public function definition(): array
    {
        return ['value' => fake()->sentence(3), 'is_active' => true, 'status_id' => \Illuminate\Support\Facades\DB::table('project_statuses')->where('slug', 'new-project')->value('id')];
    }
}
