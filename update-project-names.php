<?php

/**
 * One-time script to update ALL existing projects
 * to have a unique PROJECT-XXXXXXXX identifier in the 'name' field
 *
 * This replaces client names with technical project identifiers
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Project;
use Illuminate\Support\Facades\DB;

echo "Starting to update project names...\n\n";

// Find all projects that don't have PROJECT-XXXXXXXX format
$projectsToUpdate = Project::where('name', 'NOT LIKE', 'PROJECT-%')
    ->orWhereNull('name')
    ->orWhere('name', '')
    ->get();

echo "Found {$projectsToUpdate->count()} projects to update.\n";

if ($projectsToUpdate->isEmpty()) {
    echo "No projects to update. All projects already have PROJECT-XXXXXXXX format.\n";
    exit(0);
}

echo "\nProjects that will be updated:\n";
foreach ($projectsToUpdate as $project) {
    echo "  - ID: {$project->id}, Current name: '{$project->name}', Contract: {$project->contract_number}\n";
}

echo "\nDo you want to continue? (yes/no): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if (strtolower($line) !== 'yes') {
    echo "Update cancelled.\n";
    exit(0);
}

echo "\nStarting update...\n\n";

$updated = 0;
$errors = 0;

foreach ($projectsToUpdate as $project) {
    try {
        $oldName = $project->name;

        // Generate unique project name
        do {
            $projectName = 'PROJECT-' . strtoupper(substr(uniqid(), -8));
        } while (Project::where('name', $projectName)->exists());

        // Update the project
        $project->name = $projectName;
        $project->save();

        echo "✓ Updated project ID: {$project->id}\n";
        echo "  Old name: '{$oldName}'\n";
        echo "  New name: '{$projectName}'\n";
        echo "  Contract: {$project->contract_number}\n\n";
        $updated++;
    } catch (\Exception $e) {
        echo "✗ Error updating project ID: {$project->id} - {$e->getMessage()}\n";
        $errors++;
    }
}

echo "\n";
echo "====================================\n";
echo "Update completed!\n";
echo "Successfully updated: {$updated} projects\n";
echo "Errors: {$errors}\n";
echo "====================================\n";
