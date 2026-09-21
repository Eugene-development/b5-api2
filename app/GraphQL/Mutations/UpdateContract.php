<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Contract;

final readonly class UpdateContract
{
    /**
     * Update a contract with automatic bonus recalculation.
     */
    public function __invoke(null $_, array $args): Contract
    {
        $input = $args['input'] ?? $args;
        $contractId = $input['id'];

        return \App\Services\FinancialLedger::transaction(function () use ($input, $contractId) {
            $contract = Contract::findOrFail($contractId);

            // Запоминаем предыдущее значение is_active
            $previousIsActive = $contract->is_active;

            // Обновляем поля договора
            // Примечание: contract_number НЕ обновляется, он присваивается системой только при создании
            $contract->fill(array_filter([
                'project_id' => $input['project_id'] ?? null,
                'company_id' => $input['company_id'] ?? null,
                'value' => $input['value'] ?? null, // Номер договора от фабрики
                'contract_date' => $input['contract_date'] ?? null,
                'planned_completion_date' => $input['planned_completion_date'] ?? null,
                'actual_completion_date' => $input['actual_completion_date'] ?? null,
                'contract_amount' => $input['contract_amount'] ?? null,
                'agent_percentage' => $input['agent_percentage'] ?? null,
                'curator_percentage' => $input['curator_percentage'] ?? null,
                'is_active' => $input['is_active'] ?? null,
                'is_urgent' => $input['is_urgent'] ?? null,
            ], fn ($value) => $value !== null));

            $contract->save();

            return $contract->load(['project', 'company']);
        });
    }
}
