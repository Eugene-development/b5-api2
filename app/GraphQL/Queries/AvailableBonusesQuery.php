<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Services\PaymentService;

final readonly class AvailableBonusesQuery
{
    /**
     * Get available bonuses for payment for a specific agent (admin only).
     *
     * @param  null  $_
     * @param  array  $args
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function __invoke(null $_, array $args)
    {
        $agentId = (int) $args['agent_id'];
        $paymentService = app(PaymentService::class);

        return $paymentService->getAvailableBonusesForAgent($agentId);
    }
}
