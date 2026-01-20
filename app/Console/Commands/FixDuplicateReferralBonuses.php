<?php

namespace App\Console\Commands;

use App\Models\Bonus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Команда для исправления дублирующихся бонусов.
 * 
 * Ищет ситуации, когда один пользователь получил и личный, и реферальный бонус
 * за одну и ту же сделку (договор или заказ), и удаляет некорректный бонус.
 * 
 * Правило:
 * - Если пользователь — владелец проекта, то он должен иметь только "личный" бонус
 * - Реферальный бонус должен быть удалён, т.к. это его собственная сделка
 */
class FixDuplicateReferralBonuses extends Command
{
    protected $signature = 'bonuses:fix-duplicates {--dry-run : Только показать что будет удалено, не удалять}';

    protected $description = 'Исправляет дублирующиеся бонусы (личный + реферальный за одну сделку)';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Режим dry-run: изменения НЕ будут применены');
        }

        $this->info('Поиск дублирующихся бонусов для договоров...');
        $contractDuplicates = $this->findContractDuplicates();
        
        $this->info('Поиск дублирующихся бонусов для заказов...');
        $orderDuplicates = $this->findOrderDuplicates();

        $totalFixed = 0;

        foreach ($contractDuplicates as $duplicate) {
            $fixed = $this->fixDuplicate($duplicate, 'contract', $dryRun);
            $totalFixed += $fixed;
        }

        foreach ($orderDuplicates as $duplicate) {
            $fixed = $this->fixDuplicate($duplicate, 'order', $dryRun);
            $totalFixed += $fixed;
        }

        $action = $dryRun ? 'будет исправлено' : 'исправлено';
        $this->info("Всего $action: $totalFixed дублирующихся бонусов");

        return 0;
    }

    /**
     * Найти дубликаты бонусов для договоров.
     */
    private function findContractDuplicates(): array
    {
        return DB::table('bonuses')
            ->select('user_id', 'contract_id', DB::raw('COUNT(*) as bonus_count'))
            ->whereNotNull('contract_id')
            ->groupBy('user_id', 'contract_id')
            ->having('bonus_count', '>', 1)
            ->get()
            ->toArray();
    }

    /**
     * Найти дубликаты бонусов для заказов.
     */
    private function findOrderDuplicates(): array
    {
        return DB::table('bonuses')
            ->select('user_id', 'order_id', DB::raw('COUNT(*) as bonus_count'))
            ->whereNotNull('order_id')
            ->groupBy('user_id', 'order_id')
            ->having('bonus_count', '>', 1)
            ->get()
            ->toArray();
    }

    /**
     * Исправить дубликат бонуса.
     * 
     * Логика:
     * - Если пользователь = владелец проекта → удаляем реферальный бонус, оставляем личный
     * - Если пользователь != владелец проекта → удаляем личный бонус, оставляем реферальный
     */
    private function fixDuplicate($duplicate, string $type, bool $dryRun): int
    {
        $userId = $duplicate->user_id;
        $entityId = $type === 'contract' ? $duplicate->contract_id : $duplicate->order_id;
        $entityField = $type === 'contract' ? 'contract_id' : 'order_id';

        // Получаем все бонусы этого пользователя за эту сделку
        $bonuses = Bonus::where('user_id', $userId)
            ->where($entityField, $entityId)
            ->get();

        // Определяем владельца проекта
        $projectOwnerId = null;
        foreach ($bonuses as $bonus) {
            if ($type === 'contract' && $bonus->contract) {
                $projectOwnerId = $bonus->contract->project?->user_id;
                break;
            } elseif ($type === 'order' && $bonus->order) {
                $projectOwnerId = $bonus->order->project?->user_id;
                break;
            }
        }

        $fixedCount = 0;

        foreach ($bonuses as $bonus) {
            $isOwner = $userId === $projectOwnerId;
            $isReferralBonus = $bonus->bonus_type === 'referral' || $bonus->recipient_type === Bonus::RECIPIENT_REFERRER;

            // Если пользователь — владелец проекта, удаляем реферальные бонусы
            // Если пользователь — НЕ владелец, удаляем агентские бонусы
            $shouldDelete = false;
            $reason = '';

            if ($isOwner && $isReferralBonus) {
                $shouldDelete = true;
                $reason = 'пользователь — владелец проекта, но имеет реферальный бонус';
            } elseif (!$isOwner && !$isReferralBonus) {
                $shouldDelete = true;
                $reason = 'пользователь — НЕ владелец проекта, но имеет личный бонус';
            }

            if ($shouldDelete) {
                $this->warn("  Удаление бонуса ID={$bonus->id}: $reason");
                
                Log::info('FixDuplicateReferralBonuses: Deleting duplicate bonus', [
                    'bonus_id' => $bonus->id,
                    'user_id' => $userId,
                    "$entityField" => $entityId,
                    'bonus_type' => $bonus->bonus_type,
                    'recipient_type' => $bonus->recipient_type,
                    'is_owner' => $isOwner,
                    'project_owner_id' => $projectOwnerId,
                    'reason' => $reason,
                    'dry_run' => $dryRun,
                ]);

                if (!$dryRun && $bonus->paid_at === null) {
                    // Удаляем только неоплаченные бонусы
                    $bonus->delete();
                    $fixedCount++;
                } elseif ($bonus->paid_at !== null) {
                    $this->error("    Бонус уже оплачен, пропускаем!");
                } else {
                    $fixedCount++;
                }
            }
        }

        return $fixedCount;
    }
}
