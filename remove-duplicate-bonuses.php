<?php

/**
 * Скрипт для удаления дублирующихся бонусов
 * Оставляет только самый старый бонус для каждого заказа/договора
 *
 * Использование:
 * php remove-duplicate-bonuses.php [--dry-run]
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AgentBonus;
use Illuminate\Support\Facades\DB;

$dryRun = in_array('--dry-run', $argv);

if ($dryRun) {
    echo "🔍 Режим проверки (dry-run) - изменения не будут применены\n\n";
} else {
    echo "⚠️  ВНИМАНИЕ: Дублирующиеся бонусы будут удалены!\n";
    echo "Для проверки без удаления используйте: php remove-duplicate-bonuses.php --dry-run\n\n";
    echo "Продолжить? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim($line) !== 'yes') {
        echo "Отменено\n";
        exit(0);
    }
    fclose($handle);
    echo "\n";
}

$totalDeleted = 0;

// Удаляем дубликаты для заказов
echo "🔍 Проверка дублирующихся бонусов для заказов...\n";

$duplicateOrders = DB::table('agent_bonuses')
    ->select('order_id', DB::raw('COUNT(*) as count'))
    ->whereNotNull('order_id')
    ->groupBy('order_id')
    ->having('count', '>', 1)
    ->get();

if ($duplicateOrders->count() > 0) {
    echo "Найдено заказов с дубликатами: {$duplicateOrders->count()}\n\n";

    foreach ($duplicateOrders as $duplicate) {
        $bonuses = AgentBonus::where('order_id', $duplicate->order_id)
            ->orderBy('created_at', 'asc')
            ->get();

        $order = DB::table('orders')->where('id', $duplicate->order_id)->first();
        $orderNumber = $order ? $order->order_number : 'Unknown';

        echo "📦 Заказ: {$orderNumber}\n";
        echo "   Всего бонусов: {$bonuses->count()}\n";
        echo "   Оставляем: Бонус ID {$bonuses->first()->id} (создан {$bonuses->first()->created_at})\n";

        // Удаляем все кроме первого
        $toDelete = $bonuses->skip(1);
        foreach ($toDelete as $bonus) {
            echo "   Удаляем: Бонус ID {$bonus->id} (создан {$bonus->created_at})\n";
            if (!$dryRun) {
                $bonus->delete();
                $totalDeleted++;
            }
        }
        echo "\n";
    }
} else {
    echo "✅ Дублирующихся бонусов для заказов не найдено\n\n";
}

// Удаляем дубликаты для договоров
echo "🔍 Проверка дублирующихся бонусов для договоров...\n";

$duplicateContracts = DB::table('agent_bonuses')
    ->select('contract_id', DB::raw('COUNT(*) as count'))
    ->whereNotNull('contract_id')
    ->groupBy('contract_id')
    ->having('count', '>', 1)
    ->get();

if ($duplicateContracts->count() > 0) {
    echo "Найдено договоров с дубликатами: {$duplicateContracts->count()}\n\n";

    foreach ($duplicateContracts as $duplicate) {
        $bonuses = AgentBonus::where('contract_id', $duplicate->contract_id)
            ->orderBy('created_at', 'asc')
            ->get();

        $contract = DB::table('contracts')->where('id', $duplicate->contract_id)->first();
        $contractNumber = $contract ? $contract->contract_number : 'Unknown';

        echo "📄 Договор: {$contractNumber}\n";
        echo "   Всего бонусов: {$bonuses->count()}\n";
        echo "   Оставляем: Бонус ID {$bonuses->first()->id} (создан {$bonuses->first()->created_at})\n";

        // Удаляем все кроме первого
        $toDelete = $bonuses->skip(1);
        foreach ($toDelete as $bonus) {
            echo "   Удаляем: Бонус ID {$bonus->id} (создан {$bonus->created_at})\n";
            if (!$dryRun) {
                $bonus->delete();
                $totalDeleted++;
            }
        }
        echo "\n";
    }
} else {
    echo "✅ Дублирующихся бонусов для договоров не найдено\n\n";
}

// Итоги
if ($dryRun) {
    echo "📊 Режим проверки завершён\n";
    echo "Для удаления дубликатов запустите без флага --dry-run\n";
} else {
    echo "📊 Удаление завершено\n";
    echo "Всего удалено бонусов: {$totalDeleted}\n";
}

echo "\n✅ Готово\n";
