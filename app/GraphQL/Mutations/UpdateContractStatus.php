<?php

namespace App\GraphQL\Mutations;

use App\Models\Contract;
use App\Models\ContractStatus;

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

        // Find the contract
        $contract = Contract::findOrFail($contractId);

        // Find the status by slug
        $status = ContractStatus::where('slug', $statusSlug)
            ->where('is_active', true)
            ->firstOrFail();

        // Update the contract status directly without triggering model events
        Contract::where('id', $contractId)->update(['status_id' => $status->id]);

        // Reload the contract with fresh data
        $contract = Contract::with(['project', 'company', 'status'])->findOrFail($contractId);

        return $contract;
    }
}
