<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\AgentBonus;
use Illuminate\Support\Facades\Auth;

final readonly class AgentBonusesQuery
{
    /**
     * Get agent bonuses for the authenticated user.
     *
     * @param  null  $_
     * @param  array  $args
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function __invoke(null $_, array $args)
    {
        // Use 'api' guard explicitly for JWT authentication
        $user = Auth::guard('api')->user();

        // Debug logging
        \Illuminate\Support\Facades\Log::info('AgentBonusesQuery: Auth check', [
            'has_user' => $user !== null,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'default_guard' => Auth::getDefaultDriver(),
        ]);

        if (!$user) {
            \Illuminate\Support\Facades\Log::warning('AgentBonusesQuery: No authenticated user found via api guard');
            return collect([]);
        }

        $query = AgentBonus::where('agent_id', $user->id)
            ->with(['status', 'contract', 'order']);

        // Применяем фильтры
        $filters = $args['filters'] ?? [];

        if (!empty($filters['status_code'])) {
            $query->whereHas('status', function ($q) use ($filters) {
                $q->where('code', $filters['status_code']);
            });
        }

        if (!empty($filters['source_type'])) {
            if ($filters['source_type'] === 'contract') {
                $query->whereNotNull('contract_id');
            } elseif ($filters['source_type'] === 'order') {
                $query->whereNotNull('order_id');
            }
        }

        if (!empty($filters['date_from'])) {
            $query->where('accrued_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('accrued_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('accrued_at', 'desc')->get();
    }
}
