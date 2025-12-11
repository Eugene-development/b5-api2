<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\AgentPayment;

final readonly class AgentPaymentsAdminQuery
{
    /**
     * Get all agent payments (for admin).
     *
     * @param  null  $_
     * @param  array  $args
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function __invoke(null $_, array $args)
    {
        $query = AgentPayment::with(['agent', 'status', 'method', 'bonuses']);

        // Filter by agent if specified
        if (!empty($args['agent_id'])) {
            $query->where('agent_id', $args['agent_id']);
        }

        // Apply filters
        $filters = $args['filters'] ?? [];

        if (!empty($filters['status_code'])) {
            $query->whereHas('status', function ($q) use ($filters) {
                $q->where('code', $filters['status_code']);
            });
        }

        if (!empty($filters['date_from'])) {
            $query->where('payment_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('payment_date', '<=', $filters['date_to']);
        }

        return $query->orderBy('payment_date', 'desc')->get();
    }
}
