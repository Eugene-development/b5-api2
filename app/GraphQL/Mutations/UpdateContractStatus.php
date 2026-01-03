<?php

namespace App\GraphQL\Mutations;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Services\BonusService;
use Illuminate\Support\Facades\DB;

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

        return DB::transaction(function () use ($contractId, $statusSlug) {
            // Find the contract with relations
            $contract = Contract::with(['status', 'partnerPaymentStatus', 'agentBonus'])
                ->findOrFail($contractId);

            // Find the status by slug
            $status = ContractStatus::where('slug', $statusSlug)
                ->where('is_active', true)
                ->firstOrFail();

            // Update the contract status
            $contract->status_id = $status->id;
            $contract->save();

            // Handle bonus status change
            $bonusService = app(BonusService::class);
            $bonusService->handleContractStatusChange($contract, $statusSlug);

            // Reload the contract with fresh data
            return Contract::with(['project', 'company', 'status'])->findOrFail($contractId);
        });
    }
}
