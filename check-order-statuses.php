<?php

/**
 * Скрипт для проверки статусов заказов
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\OrderStatus;
use Illuminate\Support\Facades\DB;

echo "🔍 Проверка статусов заказов\n\n";

// Проверяем все статусы
$statuses = DB::table('order_statuses')
    ->orderBy('sort_order')
    ->get();

if ($statuses->isEmpty()) {
    echo "❌ В таблице order_statuses нет записей!\n";
    echo "Необходимо выполнить миграцию: 2025_12_13_120000_create_order_statuses_table.php\n";
    exit(1);
}

echo "📊 Все статусы заказов:\n\n";
foreach ($statuses as $status) {
    $default = $status->is_default ? '✓ DEFAULT' : '';
    $active = $status->is_active ? '✓ ACTIVE' : '✗ INACTIVE';
    echo "  - {$status->value} (slug: {$status->slug})\n";
    echo "    ID: {$status->id}\n";
    echo "    {$active} {$default}\n";
    echo "    Sort: {$status->sort_order}\n";
    echo "\n";
}

// Проверяем дефолтный статус
echo "🔍 Проверка дефолтного статуса:\n";
$defaultStatus = OrderStatus::getDefault();

if ($defaultStatus) {
    echo "✅ Дефолтный статус найден:\n";
    echo "  - ID: {$defaultStatus->id}\n";
    echo "  - Название: {$defaultStatus->value}\n";
    echo "  - Slug: {$defaultStatus->slug}\n";
    echo "  - is_default: " . ($defaultStatus->is_default ? 'true' : 'false') . "\n";
    echo "  - is_active: " . ($defaultStatus->is_active ? 'true' : 'false') . "\n";
} else {
    echo "❌ Дефолтный статус НЕ найден!\n";
    echo "Проверьте, что в таблице order_statuses есть запись с is_default=true и is_active=true\n";

    // Пытаемся найти первый активный статус
    $firstActive = OrderStatus::where('is_active', true)
        ->orderBy('sort_order')
        ->first();

    if ($firstActive) {
        echo "\n⚠️  Будет использован первый активный статус:\n";
        echo "  - ID: {$firstActive->id}\n";
        echo "  - Название: {$firstActive->value}\n";
        echo "  - Slug: {$firstActive->slug}\n";
    } else {
        echo "\n❌ Активных статусов вообще нет!\n";
    }
}

echo "\n✅ Проверка завершена\n";
