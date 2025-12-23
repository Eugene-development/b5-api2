<?php

/**
 * Скрипт для проверки статуса конкретного заказа
 *
 * Использование:
 * php check-order-status.php <order_id или order_number>
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Order;
use Illuminate\Support\Facades\DB;

if ($argc < 2) {
    echo "Usage: php check-order-status.php <order_id или order_number>\n";
    echo "Example: php check-order-status.php ORDER-12345-678\n";
    exit(1);
}

$identifier = $argv[1];

echo "🔍 Проверка заказа: {$identifier}\n\n";

// Пытаемся найти заказ по ID или номеру
$order = null;
if (strlen($identifier) === 26) {
    // Похоже на ULID
    $order = Order::with(['status', 'project'])->find($identifier);
} else {
    // Ищем по номеру
    $order = Order::with(['status', 'project'])->where('order_number', $identifier)->first();
}

if (!$order) {
    echo "❌ Заказ не найден\n";
    exit(1);
}

echo "📦 Информация о заказе:\n";
echo "  - ID: {$order->id}\n";
echo "  - Номер: {$order->order_number}\n";
echo "  - Проект: " . ($order->project ? $order->project->value : 'не указан') . "\n";
echo "  - Сумма: " . ($order->order_amount ?? 'не указана') . "\n";
echo "  - is_active: " . ($order->is_active ? 'true' : 'false') . "\n";
echo "\n";

echo "📊 Статус заказа:\n";
echo "  - status_id в БД: " . ($order->status_id ?? 'NULL') . "\n";

if ($order->status_id) {
    if ($order->status) {
        echo "  - ✅ Связь status загружена:\n";
        echo "    - ID: {$order->status->id}\n";
        echo "    - Название: {$order->status->value}\n";
        echo "    - Slug: {$order->status->slug}\n";
        echo "    - is_active: " . ($order->status->is_active ? 'true' : 'false') . "\n";
    } else {
        echo "  - ❌ Связь status НЕ загружена (status_id есть, но связь пустая)\n";

        // Пытаемся загрузить статус напрямую
        $status = DB::table('order_statuses')->where('id', $order->status_id)->first();
        if ($status) {
            echo "  - ⚠️  Статус существует в БД:\n";
            echo "    - ID: {$status->id}\n";
            echo "    - Название: {$status->value}\n";
            echo "    - Slug: {$status->slug}\n";
        } else {
            echo "  - ❌ Статус с ID {$order->status_id} не найден в таблице order_statuses\n";
        }
    }
} else {
    echo "  - ❌ status_id = NULL (статус не установлен)\n";
    echo "\n";
    echo "🔧 Установка дефолтного статуса...\n";

    $defaultStatus = DB::table('order_statuses')
        ->where('is_default', true)
        ->where('is_active', true)
        ->first();

    if ($defaultStatus) {
        DB::table('orders')
            ->where('id', $order->id)
            ->update(['status_id' => $defaultStatus->id]);

        echo "✅ Статус установлен: {$defaultStatus->value} (ID: {$defaultStatus->id})\n";
    } else {
        echo "❌ Не найден дефолтный статус для установки\n";
    }
}

echo "\n✅ Проверка завершена\n";
