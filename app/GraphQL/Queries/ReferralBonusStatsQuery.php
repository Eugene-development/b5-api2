<?php

namespace App\GraphQL\Queries;

use App\Services\ReferralBonusService;
use Illuminate\Support\Facades\Auth;

/**
 * GraphQL Query для получения статистики реферальных бонусов.
 */
class ReferralBonusStatsQuery
{
    protected ReferralBonusService $referralBonusService;

    public function __construct(ReferralBonusService $referralBonusService)
    {
        $this->referralBonusService = $referralBonusService;
    }

    /**
     * Получить статистику реферальных бонусов текущего пользователя.
     *
     * @param mixed $root
     * @param array $args
     * @return array
     */
    public function __invoke($root, array $args): array
    {
        $user = Auth::user();

        if (!$user) {
            return [
                'total_pending' => 0,
                'total_available' => 0,
                'total_paid' => 0,
                'total' => 0,
                'referrals' => [],
            ];
        }

        return $this->referralBonusService->getReferralStats($user->id);
    }
}
