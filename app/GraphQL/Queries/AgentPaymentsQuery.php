<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\AgentPayment;
use Illuminate\Support\Facades\Auth;

final readonly class AgentPaymentsQuery
{
    /**
     * Get agent payments for the authenticated user.
     *
     * @param  null  $_
     * @param  array  $args
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function __invoke(null $_, array $args)
    {
        $user = Auth::user();
        if (!$user) {
            return collect([]);
        }

        $query = AgentPayment::where('agent_id', $user->id)
            ->with(['status', 'method', 'bonuses']);

        // Применяем фильтры
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
