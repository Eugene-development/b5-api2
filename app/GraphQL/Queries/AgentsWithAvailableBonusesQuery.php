<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\AgentBonus;
use App\Models\BonusStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AgentsWithAvailableBonusesQuery
{
    /**
     * Get agents with available bonuses for payment.
     *
     * @param  null  $_
     * @param  array  $args
     * @return array
     */
    public function __invoke(null $_, array $args)
    {
        // Get the available_for_payment status
        $availableStatus = BonusStatus::where('code', 'available_for_payment')->first();

        if (!$availableStatus) {
            return [];
        }

        // Get agents with available bonuses
        $agents = User::whereHas('agentBonuses', function ($query) use ($availableStatus) {
            $query->where('status_id', $availableStatus->id)
                  ->where('commission_amount', '>', 0);
        })
        ->withCount(['agentBonuses as available_bonuses_count' => function ($query) use ($availableStatus) {
            $query->where('status_id', $availableStatus->id)
                  ->where('commission_amount', '>', 0);
        }])
        ->withSum(['agentBonuses as available_bonuses_total' => function ($query) use ($availableStatus) {
            $query->where('status_id', $availableStatus->id)
                  ->where('commission_amount', '>', 0);
        }], 'commission_amount')
        ->get();

        return $agents->map(function ($agent) {
            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'available_bonuses_count' => (int) ($agent->available_bonuses_count ?? 0),
                'available_bonuses_total' => (float) ($agent->available_bonuses_total ?? 0),
            ];
        })->toArray();
    }
}
