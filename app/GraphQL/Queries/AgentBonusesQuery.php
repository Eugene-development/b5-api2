<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Bonus;
use Illuminate\Support\Facades\Auth;

final readonly class AgentBonusesQuery
{
    /**
     * Get bonuses for the authenticated user.
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

        $query = Bonus::where('user_id', $user->id)
            ->with(['status', 'contract', 'contract.status', 'contract.partnerPaymentStatus', 'order', 'referralUser']);

        // Фильтруем бонусы: показываем только те, где договор в статусе "Заключён" или далее
        // (т.е. исключаем договоры в статусе "Обработка" / preparing)
        // Для реферальных бонусов и бонусов от заказов — показываем всегда
        $query->where(function ($q) {
            $q->whereHas('contract', function ($contractQuery) {
                $contractQuery->whereHas('status', function ($statusQuery) {
                    // Исключаем статус "Обработка" (preparing)
                    $statusQuery->where('slug', '!=', 'preparing');
                });
            })
            // Или это бонус от заказа (не от договора)
            ->orWhereNotNull('order_id');
        });

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

        // Фильтр по типу получателя
        if (!empty($filters['recipient_type'])) {
            $query->where('recipient_type', $filters['recipient_type']);
        }

        // Фильтр по типу бонуса (legacy)
        if (!empty($filters['bonus_type'])) {
            if ($filters['bonus_type'] === 'agent') {
                $query->where(function ($q) {
                    $q->where('bonus_type', 'agent')
                      ->orWhereNull('bonus_type');
                });
            } elseif ($filters['bonus_type'] === 'referral') {
                $query->where('bonus_type', 'referral');
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
