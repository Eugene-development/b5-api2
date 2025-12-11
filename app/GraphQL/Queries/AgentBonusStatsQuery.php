<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Services\BonusService;
use Illuminate\Support\Facades\Auth;

final readonly class AgentBonusStatsQuery
{
    /**
     * Get agent bonus statistics for the authenticated user.
     *
     * @param  null  $_
     * @param  array  $args
     * @return array
     */
    public function __invoke(null $_, array $args): array
    {
        $user = Auth::user();
        if (!$user) {
            return [
                'total_accrued' => 0,
                'total_available' => 0,
                'total_paid' => 0,
            ];
        }

        $filters = $args['filters'] ?? null;
        $bonusService = app(BonusService::class);

        return $bonusService->getAgentStats($user->id, $filters);
    }
}
