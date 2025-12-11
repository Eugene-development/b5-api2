<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Order;
use App\Services\BonusService;
use Illuminate\Console\Command;

/**
 * Команда для миграции существующих договоров и закупок в таблицу agent_bonuses.
 *
 * Создает записи в agent_bonuses для всех договоров и закупок, у которых их еще нет.
 * Это необходимо для корректной работы страницы финансов.
 */
class MigrateContractBonuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bonuses:migrate
                            {--dry-run : Run without making changes}
                            {--force : Force migration even if bonuses exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate existing contracts and orders to agent_bonuses table';

    /**
     * Execute the console command.
     */
    public function handle(BonusService $bonusService): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('Starting bonus migration...');
        $this->newLine();

        // Migrate contracts
        $this->info('Processing contracts...');
        $contracts = Contract::with('agentBonus')->get();
        $contractsCreated = 0;
        $contractsSkipped = 0;

        foreach ($contracts as $contract) {
            // Skip if bonus already exists (unless force flag is set)
            if ($contract->agentBonus && !$force) {
                $contractsSkipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("Would create bonus for contract {$contract->contract_number}");
                $contractsCreated++;
            } else {
                // Delete existing bonus if force flag is set
                if ($force && $contract->agentBonus) {
                    $contract->agentBonus->delete();
                }

                $bonus = $bonusService->createBonusForContract($contract);
                if ($bonus) {
                    $this->line("✓ Created bonus for contract {$contract->contract_number}");
                    $contractsCreated++;
                } else {
                    $this->line("✗ Skipped contract {$contract->contract_number} (inactive or zero amount)");
                    $contractsSkipped++;
                }
            }
        }

        $this->newLine();
        $this->info("Contracts: {$contractsCreated} created, {$contractsSkipped} skipped");
        $this->newLine();

        // Migrate orders
        $this->info('Processing orders...');
        $orders = Order::with('agentBonus')->get();
        $ordersCreated = 0;
        $ordersSkipped = 0;

        foreach ($orders as $order) {
            // Skip if bonus already exists (unless force flag is set)
            if ($order->agentBonus && !$force) {
                $ordersSkipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("Would create bonus for order {$order->order_number}");
                $ordersCreated++;
            } else {
                // Delete existing bonus if force flag is set
                if ($force && $order->agentBonus) {
                    $order->agentBonus->delete();
                }

                $bonus = $bonusService->createBonusForOrder($order);
                if ($bonus) {
                    $this->line("✓ Created bonus for order {$order->order_number}");
                    $ordersCreated++;
                } else {
                    $this->line("✗ Skipped order {$order->order_number} (inactive or zero amount)");
                    $ordersSkipped++;
                }
            }
        }

        $this->newLine();
        $this->info("Orders: {$ordersCreated} created, {$ordersSkipped} skipped");
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN - No changes were made');
            $this->info('Run without --dry-run to apply changes');
        } else {
            $this->info('Migration completed successfully!');
        }

        return Command::SUCCESS;
    }
}
