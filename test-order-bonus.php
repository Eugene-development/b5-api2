<?php

/**
 * Тестовый скрипт для проверки создания бонусов для заказов
 *
 * Использование:
 * php test-order-bonus.php <order_id>
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Order;
use App\Models\AgentBonus;

if ($argc < 2) {
    echo "Usage: php test-order-bonus.php <order_id>\n";
    echo "Example: php test-order-bonus.php 01JFABC123XYZ456789012\n";
    exit(1);
}

$orderId = $argv[1];

echo "🔍 Проверка заказа: {$orderId}\n\n";

// Получаем заказ
$order = Order::with(['project', 'status', 'agentBonus'])->find($orderId);

if (!$order) {
    echo "❌ Заказ не найден\n";
    exit(1);
}

echo "📦 Информация о заказе:\n";
echo "  - Номер: {$order->order_number}\n";
echo "  - Проект: " . ($order->project ? $order->project->value : 'не указан') . "\n";
echo "  - Сумма: " . ($order->order_amount ?? 'не указана') . "\n";
echo "  - Процент агента: {$order->agent_percentage}%\n";
echo "  - is_active: " . ($order->is_active ? 'true' : 'false') . "\n";
echo "  - Статус: " . ($order->status ? $order->status->value . " ({$order->status->slug})" : 'не указан') . "\n";
echo "\n";

// Проверяем условия создания бонуса
echo "✅ Проверка условий создания бонуса:\n";

if (!$order->is_active) {
    echo "  ❌ Заказ неактивен (is_active = false)\n";
} else {
    echo "  ✅ Заказ активен\n";
}

if (!$order->order_amount || $order->order_amount <= 0) {
    echo "  ❌ Сумма заказа не указана или равна 0\n";
} else {
    echo "  ✅ Сумма заказа: {$order->order_amount}\n";
}

// Получаем agent_id из проекта
$agentId = null;
if ($order->project) {
    $agentId = $order->project->user_id;
}

if (!$agentId) {
    echo "  ❌ Не найден agent_id в проекте\n";
} else {
    echo "  ✅ Agent ID: {$agentId}\n";
}

echo "\n";

// Проверяем наличие бонуса
$bonus = $order->agentBonus;

if ($bonus) {
    echo "💰 Бонус найден:\n";
    echo "  - ID: {$bonus->id}\n";
    echo "  - Agent ID: {$bonus->agent_id}\n";
    echo "  - Сумма комиссии: {$bonus->commission_amount}\n";
    echo "  - Статус: " . ($bonus->status ? $bonus->status->name . " ({$bonus->status->code})" : 'не указан') . "\n";
    echo "  - Начислено: {$bonus->accrued_at}\n";
    echo "  - Доступно: " . ($bonus->available_at ?? 'не указано') . "\n";
    echo "  - Выплачено: " . ($bonus->paid_at ?? 'не указано') . "\n";
} else {
    echo "❌ Бонус не найден для этого заказа\n";
    echo "\n";
    echo "🔧 Попытка создать бонус вручную...\n";

    if ($order->is_active && $order->order_amount && $order->order_amount > 0 && $agentId) {
        $bonusService = app(\App\Services\BonusService::class);
        $newBonus = $bonusService->createBonusForOrder($order);

        if ($newBonus) {
            echo "✅ Бонус успешно создан:\n";
            echo "  - ID: {$newBonus->id}\n";
            echo "  - Сумма комиссии: {$newBonus->commission_amount}\n";
        } else {
            echo "❌ Не удалось создать бонус\n";
        }
    } else {
        echo "❌ Условия для создания бонуса не выполнены\n";
    }
}

echo "\n";
echo "✅ Проверка завершена\n";
