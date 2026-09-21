<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use Illuminate\Support\Facades\Auth;

final readonly class AgentBonusesQuery
{
    /**
     * Get bonuses for the authenticated user.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function __invoke(null $_, array $args)
    {
        $user = Auth::user();
        if (! $user) {
            return collect([]);
        }

        $filters = array_merge($args['filters'] ?? [], ['user_id' => $user->id]);

        if (empty($filters['recipient_type'])) {
            $filters['requester_type'] = 'agent';
        }

        return app(\App\Services\BonusStatisticsService::class)->query($filters)->orderByDesc('accrued_at')->orderByDesc('id')->get();
    }
}
