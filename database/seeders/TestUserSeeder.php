<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TestUserSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Create a test user with a known secret key
        User::create([
            'name' => 'Test Agent',
            'email' => 'agent@test.com',
            'password' => bcrypt('password'),
            'key' => '01HZY8Y9G5F8M9B6W7K3NQ4Z8X',
            'bun' => false,
            'is_active' => true,
        ]);
    }
}
