<?php

namespace App\Console\Commands;

use App\Models\Bonus;
use App\Models\Contract;
use App\Models\Order;
use App\Models\ProjectUser;
use App\Services\BonusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateMissingCuratorBonuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bonuses:create-curator-bonuses {--dry-run : Show what would be created without actually creating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create missing curator bonuses for contracts and orders where curator is assigned but bonus does not exist';

    /**
     * Execute the console command.
     */
    public function handle(BonusService $bonusService): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        $this->info('Starting curator bonuses creation...');

        $contractBonusesCreated = 0;
        $orderBonusesCreated = 0;

        // Получаем все проекты с назначенными кураторами
        $curatorAssignments = ProjectUser::where('role', 'curator')->get();

        $this->info("Found {$curatorAssignments->count()} curator assignments");

        foreach ($curatorAssignments as $assignment) {
            $projectId = $assignment->project_id;
            $curatorId = $assignment->user_id;

            // Обрабатываем договоры
            $contracts = Contract::where('project_id', $projectId)
                ->where('is_active', true)
                ->get();

            foreach ($contracts as $contract) {
                // Проверяем, есть ли уже бонус куратора
                $existingBonus = Bonus::where('contract_id', $contract->id)
                    ->where('recipient_type', Bonus::RECIPIENT_CURATOR)
                    ->first();

                if (!$existingBonus) {
                    if ($dryRun) {
                        $this->line("  [DRY] Would create curator bonus for contract {$contract->contract_number}");
                        $contractBonusesCreated++;
                    } else {
                        $bonus = $bonusService->createCuratorBonusForContract($contract);
                        if ($bonus) {
                            $this->line("  ✓ Created curator bonus for contract {$contract->contract_number}: {$bonus->commission_amount}");
                            $contractBonusesCreated++;
                        }
                    }
                }
            }

            // Обрабатываем заказы
            $orders = Order::where('project_id', $projectId)
                ->where('is_active', true)
                ->get();

            foreach ($orders as $order) {
                // Проверяем, есть ли уже бонус куратора
                $existingBonus = Bonus::where('order_id', $order->id)
                    ->where('recipient_type', Bonus::RECIPIENT_CURATOR)
                    ->first();

                if (!$existingBonus) {
                    if ($dryRun) {
                        $this->line("  [DRY] Would create curator bonus for order {$order->order_number}");
                        $orderBonusesCreated++;
                    } else {
                        $bonus = $bonusService->createCuratorBonusForOrder($order);
                        if ($bonus) {
                            $this->line("  ✓ Created curator bonus for order {$order->order_number}: {$bonus->commission_amount}");
                            $orderBonusesCreated++;
                        }
                    }
                }
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->info("  Contract bonuses created: {$contractBonusesCreated}");
        $this->info("  Order bonuses created: {$orderBonusesCreated}");
        $this->info("  Total: " . ($contractBonusesCreated + $orderBonusesCreated));

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a dry run. Run without --dry-run to actually create bonuses.');
        }

        return Command::SUCCESS;
    }
}
