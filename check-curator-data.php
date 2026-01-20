<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Проверка данных кураторов в проектах ===\n\n";

// Получаем проекты с техзаданиями
$projects = DB::table('projects')
    ->join('technical_specifications', 'projects.id', '=', 'technical_specifications.project_id')
    ->select('projects.id', 'projects.project_number', 'projects.value', 'projects.user_id')
    ->distinct()
    ->limit(10)
    ->get();

echo "Найдено проектов с техзаданиями: " . $projects->count() . "\n\n";

foreach ($projects as $project) {
    echo "Проект #{$project->id}: {$project->project_number}\n";
    echo "  Название: {$project->value}\n";
    echo "  user_id (агент): {$project->user_id}\n";

    // Проверяем записи в project_user для этого проекта
    $projectUsers = DB::table('project_user')
        ->where('project_id', $project->id)
        ->get();

    echo "  Записей в project_user: " . $projectUsers->count() . "\n";

    foreach ($projectUsers as $pu) {
        $user = DB::table('users')->where('id', $pu->user_id)->first();
        echo "    - Роль: {$pu->role}, User: {$user->name} (ID: {$user->id})\n";
    }

    // Проверяем, есть ли куратор
    $curator = DB::table('project_user')
        ->where('project_id', $project->id)
        ->where('role', 'curator')
        ->first();

    if ($curator) {
        $curatorUser = DB::table('users')->where('id', $curator->user_id)->first();
        echo "  ✓ Куратор найден: {$curatorUser->name}\n";
    } else {
        echo "  ✗ Куратор НЕ найден в project_user\n";
    }

    echo "\n";
}

echo "\n=== Статистика ===\n";
$totalProjects = DB::table('projects')->count();
$projectsWithCurator = DB::table('project_user')
    ->where('role', 'curator')
    ->distinct('project_id')
    ->count();

echo "Всего проектов: {$totalProjects}\n";
echo "Проектов с куратором: {$projectsWithCurator}\n";
echo "Проектов без куратора: " . ($totalProjects - $projectsWithCurator) . "\n";
