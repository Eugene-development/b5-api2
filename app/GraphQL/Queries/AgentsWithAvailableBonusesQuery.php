<?php

namespace App\GraphQL\Queries;

use App\Models\Bonus;
use App\Models\User;
use App\Services\BonusPaymentService;

final class AgentsWithAvailableBonusesQuery
{
    public function __invoke(null $_, array $args): array
    {
        $result = [];
        $service = app(BonusPaymentService::class);
        foreach (User::whereIn('id', Bonus::select('user_id')->whereNull('paid_at'))->get() as $user) {
            $bonuses = $service->getAvailableBonuses($user->id, 'agent');
            if ($bonuses->isNotEmpty()) {
                $result[] = ['id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                    'available_bonuses_count' => $bonuses->count(), 'available_bonuses_total' => $service->calculateAvailableBalance($user->id, 'agent')];
            }
        }

        return $result;
    }
}
