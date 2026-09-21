<?php

namespace App\GraphQL\Queries;

use App\Models\BonusPaymentRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class BonusPaymentRequestsQuery
{
    public function builder($root, array $args): Builder
    {
        $filters = $args['filters'] ?? $args;
        $q = BonusPaymentRequest::with(['agent.phones', 'status']);
        if (Auth::user()->status?->slug !== 'admin') {
            $q->where('agent_id', Auth::id())->where('requester_type', 'curator');
        }
        foreach (['status_id', 'requester_type'] as $field) {
            if (! empty($filters[$field])) {
                $q->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['date_from'])) {
            $q->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->where('created_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $q->where(function ($s) use ($term) {
                $s->where('comment', 'like', $term)->orWhere('contact_info', 'like', $term)
                    ->orWhere('card_number', 'like', $term)->orWhere('phone_number', 'like', $term)
                    ->orWhere('id', 'like', $term)
                    ->orWhereHas('status', fn ($status) => $status->where('name', 'like', $term))
                    ->orWhereHas('agent', fn ($a) => $a->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhereHas('phones', fn ($phone) => $phone->where('value', 'like', $term)));
            });
        }

        return $q->orderByDesc('created_at')->orderByDesc('id');
    }
}
