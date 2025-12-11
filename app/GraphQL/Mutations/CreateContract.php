<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Contract;
use App\Services\BonusService;
use Illuminate\Support\Facades\DB;

final readonly class CreateContract
{
    /**
     * Create a new contract with automatic bonus creation.
     *
     * @param  null  $_
     * @param  array  $args
     * @return Contract
     */
    public function __invoke(null $_, array $args): Contract
    {
        $input = $args['input'] ?? $args;

        return DB::transaction(function () use ($input) {
            // Создаём договор
            $contract = Contract::create([
                'project_id' => $input['project_id'],
                'company_id' => $input['company_id'],
                'contract_number' => $input['contract_number'] ?? null,
                'contract_date' => $input['contract_date'],
                'planned_completion_date' => $input['planned_completion_date'],
                'actual_completion_date' => $input['actual_completion_date'] ?? null,
                'contract_amount' => $input['contract_amount'] ?? null,
                'agent_percentage' => $input['agent_percentage'] ?? 3.00,
                'curator_percentage' => $input['curator_percentage'] ?? 2.00,
                'is_active' => $input['is_active'] ?? true,
                'partner_payment_status_id' => 1, // pending по умолчанию
            ]);

            // Автоматически создаём бонус агента
            $bonusService = app(BonusService::class);
            $bonusService->createBonusForContract($contract);

            // Load relationships and return
            return $contract->load(['project', 'company']);
        });
    }
}
