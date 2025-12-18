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
        // Use 'api' guard explicitly for JWT authentication
        $user = Auth::guard('api')->user();

        // Debug logging
        \Illuminate\Support\Facades\Log::info('AgentBonusStatsQuery: Auth check', [
            'has_user' => $user !== null,
            'user_id' => $user?->id,
        ]);

        if (!$user) {
            \Illuminate\Support\Facades\Log::warning('AgentBonusStatsQuery: No authenticated user found via api guard');
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
