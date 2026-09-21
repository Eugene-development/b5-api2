<?php

namespace App\GraphQL\Queries;

use App\Services\BonusStatisticsService;
use Illuminate\Support\Facades\Auth;

final class AdminBonusStatsQuery
{
    public function __invoke(null $_, array $args): array
    {
        $filters = $args['filters'] ?? [];
        if (Auth::user()->status?->slug !== 'admin') {
            $filters['user_id'] = Auth::id();
            $filters['recipient_type'] = 'curator';
        }

        return app(BonusStatisticsService::class)->calculate($filters);
    }
}
