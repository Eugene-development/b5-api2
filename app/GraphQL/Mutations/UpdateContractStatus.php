<?php

namespace App\GraphQL\Mutations;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Services\BonusService;
use Illuminate\Support\Facades\Log;

class UpdateContractStatus
{
    /**
     * Update the status of a contract.
     *
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $args): Contract
    {
        $contractId = $args['contract_id'];
        $statusSlug = $args['status_slug'];

        Log::info('UpdateContractStatus: Starting', [
            'contract_id' => $contractId,
            'status_slug' => $statusSlug,
        ]);

        // Find the contract
        $contract = Contract::findOrFail($contractId);

        // Find the status by slug
        $status = ContractStatus::where('slug', $statusSlug)
            ->where('is_active', true)
            ->firstOrFail();

        // Update the contract status
        $contract->status_id = $status->id;
        $contract->save();

        // Reload with relationships
        $contract->load(['project', 'company', 'status', 'partnerPaymentStatus', 'agentBonus']);

        // Обновляем статус бонуса при изменении статуса договора
        $bonusService = app(BonusService::class);
        $bonusService->handleContractStatusChange($contract, $statusSlug);

        Log::info('UpdateContractStatus: Success', [
            'contract_id' => $contract->id,
            'new_status' => $status->value,
        ]);

        return $contract;
    }
}
