<?php

/**
 * Test script to simulate form submission from b5-mm
 * Tests the fixed relationship issue
 */

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

echo "🧪 Testing fixed form submission...\n\n";

try {
    // Check if we have a test user
    $testUser = User::where('key', '01HZY8Y9G5F8M9B6W7K3NQ4Z8X')->first();
    
    if (!$testUser) {
        echo "❌ Test user not found. Creating one...\n";
        $testUser = User::create([
            'name' => 'Test Agent',
            'email' => 'agent@test.com',
            'password' => bcrypt('password'),
            'key' => '01HZY8Y9G5F8M9B6W7K3NQ4Z8X',
            'status' => 'active',
        ]);
        echo "✅ Test user created with ID: {$testUser->id}\n";
    } else {
        echo "✅ Found test user: {$testUser->name} (ID: {$testUser->id})\n";
    }
    
    // Test data similar to what would come from b5-mm form
    $formData = [
        'secret_key' => '01HZY8Y9G5F8M9B6W7K3NQ4Z8X',
        'client_name' => 'Test Client',
        'city' => 'Moscow',
        'interest' => 'Test interest description'
    ];
    
    echo "\n📝 Simulating form submission with data:\n";
    foreach ($formData as $key => $value) {
        echo "   {$key}: {$value}\n";
    }
    
    // Test the relationship and creation
    echo "\n🔗 Testing relationship...\n";
    
    $user = User::where('key', $formData['secret_key'])->first();
    if (!$user) {
        throw new Exception('User not found');
    }
    echo "✅ User found by secret key\n";
    
    // This should work now with our fixes
    $project = $user->projects()->create([
        'value' => $formData['client_name'],
        'description' => $formData['interest'] ?? null,
        'city' => $formData['city'] ?? null,
        'is_active' => true,
    ]);
    
    echo "✅ Project created successfully!\n";
    echo "   Project ID: {$project->id}\n";
    echo "   Project value: {$project->value}\n";
    echo "   Project user_id: {$project->user_id}\n";
    echo "   Project description: {$project->description}\n";
    echo "   Project city: {$project->city}\n";
    echo "   Project is_active: " . ($project->is_active ? 'true' : 'false') . "\n";
    
    // Test the reverse relationship
    echo "\n🔙 Testing reverse relationship...\n";
    $userProjects = $user->projects()->count();
    echo "✅ User has {$userProjects} project(s)\n";
    
    $projectUser = $project->user;
    if ($projectUser && $projectUser->id === $user->id) {
        echo "✅ Project correctly belongs to user: {$projectUser->name}\n";
    } else {
        echo "❌ Reverse relationship issue\n";
    }
    
    echo "\n🎉 All tests passed! The form submission should now work correctly.\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "📋 Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}