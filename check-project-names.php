<?php

/**
 * Check what's in the name field of projects
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Project;

echo "Checking project names...\n\n";

// Get first 10 projects
$projects = Project::orderBy('created_at', 'desc')->limit(10)->get();

echo "Found {$projects->count()} recent projects.\n\n";

foreach ($projects as $project) {
    echo "Project ID: {$project->id}\n";
    echo "  Name: " . ($project->name ?: '[EMPTY]') . "\n";
    echo "  Value attribute: " . ($project->value ?: '[EMPTY]') . "\n";
    echo "  Contract Number: " . ($project->contract_number ?: '[EMPTY]') . "\n";
    echo "  Created: {$project->created_at}\n";
    echo "\n";
}
