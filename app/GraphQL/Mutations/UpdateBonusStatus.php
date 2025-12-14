<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\AgentBonus;
use App\Models\BonusStatus;
use Carbon\Carbon;

final readonly class UpdateBonusStatus
{
    /**
     * Update bonus status.
     *
     * @param  null  $_
     * @param  array  $args
     * @return AgentBonus
     */
    public function __invoke(null $_, array $args): AgentBonus
    {
        $bonus = AgentBonus::findOrFail($args['bonus_id']);
        $status = BonusStatus::where('code', $args['status_code'])->firstOrFail();

        $bonus->status_id = $status->id;

        // Set paid_at date when status changes to paid
        if ($args['status_code'] === 'paid' && !$bonus->paid_at) {
            $bonus->paid_at = Carbon::now();
        }

        // Set available_at date when status changes to available_for_payment
        if ($args['status_code'] === 'available_for_payment' && !$bonus->available_at) {
            $bonus->available_at = Carbon::now();
        }

        // Clear paid_at if status is not paid
        if ($args['status_code'] !== 'paid') {
            $bonus->paid_at = null;
        }

        $bonus->save();
        $bonus->load('status');

        return $bonus;
    }
}
