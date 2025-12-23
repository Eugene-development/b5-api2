<?php

/**
 * Скрипт для проверки дублирующихся бонусов
 *
 * Использование:
 * php check-duplicate-bonuses.php [agent_id]
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$agentId = $argv[1] ?? null;

echo "🔍 Проверка дублирующихся бонусов\n\n";

// Проверяем дубликаты по заказам
$duplicateOrders = DB::table('agent_bonuses')
    ->select('order_id', DB::raw('COUNT(*) as count'))
    ->whereNotNull('order_id')
    ->when($agentId, function ($query, $agentId) {
        return $query->where('agent_id', $agentId);
    })
    ->groupBy('order_id')
    ->having('count', '>', 1)
    ->get();

if ($duplicateOrders->count() > 0) {
    echo "❌ Найдены дублирующиеся бонусы для заказов:\n\n";

    foreach ($duplicateOrders as $duplicate) {
        $bonuses = DB::table('agent_bonuses as ab')
            ->leftJoin('orders as o', 'ab.order_id', '=', 'o.id')
            ->leftJoin('bonus_statuses as bs', 'ab.status_id', '=', 'bs.id')
            ->where('ab.order_id', $duplicate->order_id)
            ->select('ab.id', 'ab.order_id', 'o.order_number', 'ab.commission_amount', 'bs.name as status', 'ab.created_at')
            ->get();

        echo "📦 Заказ: {$bonuses[0]->order_number} (ID: {$duplicate->order_id})\n";
        echo "   Количество бонусов: {$duplicate->count}\n";

        foreach ($bonuses as $bonus) {
            echo "   - Бонус ID: {$bonus->id}\n";
            echo "     Сумма: {$bonus->commission_amount}\n";
            echo "     Статус: {$bonus->status}\n";
            echo "     Создан: {$bonus->created_at}\n";
        }
        echo "\n";
    }
} else {
    echo "✅ Дублирующихся бонусов для заказов не найдено\n\n";
}

// Проверяем дубликаты по договорам
$duplicateContracts = DB::table('agent_bonuses')
    ->select('contract_id', DB::raw('COUNT(*) as count'))
    ->whereNotNull('contract_id')
    ->when($agentId, function ($query, $agentId) {
        return $query->where('agent_id', $agentId);
    })
    ->groupBy('contract_id')
    ->having('count', '>', 1)
    ->get();

if ($duplicateContracts->count() > 0) {
    echo "❌ Найдены дублирующиеся бонусы для договоров:\n\n";

    foreach ($duplicateContracts as $duplicate) {
        $bonuses = DB::table('agent_bonuses as ab')
            ->leftJoin('contracts as c', 'ab.contract_id', '=', 'c.id')
            ->leftJoin('bonus_statuses as bs', 'ab.status_id', '=', 'bs.id')
            ->where('ab.contract_id', $duplicate->contract_id)
            ->select('ab.id', 'ab.contract_id', 'c.contract_number', 'ab.commission_amount', 'bs.name as status', 'ab.created_at')
            ->get();

        echo "📄 Договор: {$bonuses[0]->contract_number} (ID: {$duplicate->contract_id})\n";
        echo "   Количество бонусов: {$duplicate->count}\n";

        foreach ($bonuses as $bonus) {
            echo "   - Бонус ID: {$bonus->id}\n";
            echo "     Сумма: {$bonus->commission_amount}\n";
            echo "     Статус: {$bonus->status}\n";
            echo "     Создан: {$bonus->created_at}\n";
        }
        echo "\n";
    }
} else {
    echo "✅ Дублирующихся бонусов для договоров не найдено\n\n";
}

// Предложение решения
if ($duplicateOrders->count() > 0 || $duplicateContracts->count() > 0) {
    echo "🔧 Рекомендации:\n";
    echo "1. Удалить дублирующиеся бонусы (оставить самый старый)\n";
    echo "2. Проверить логику создания бонусов в модели Order/Contract\n";
    echo "3. Добавить уникальный индекс на (order_id) и (contract_id) в таблице agent_bonuses\n";
    echo "\n";
    echo "Для удаления дубликатов используйте:\n";
    echo "php artisan tinker\n";
    echo ">>> \$duplicates = App\\Models\\AgentBonus::select('order_id')\n";
    echo "...     ->whereNotNull('order_id')\n";
    echo "...     ->groupBy('order_id')\n";
    echo "...     ->havingRaw('COUNT(*) > 1')\n";
    echo "...     ->pluck('order_id');\n";
    echo ">>> foreach (\$duplicates as \$orderId) {\n";
    echo "...     \$bonuses = App\\Models\\AgentBonus::where('order_id', \$orderId)\n";
    echo "...         ->orderBy('created_at', 'asc')\n";
    echo "...         ->get();\n";
    echo "...     // Удаляем все кроме первого\n";
    echo "...     \$bonuses->skip(1)->each(fn(\$b) => \$b->delete());\n";
    echo "... }\n";
}

echo "\n✅ Проверка завершена\n";
