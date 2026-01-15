<?php

namespace App\GraphQL\Resolvers;

use App\Models\Order;
use App\Models\Comment;

class OrderCommentsResolver
{
    /**
     * Get comments for an order via commentables pivot table.
     *
     * @param Order $order
     * @return \Illuminate\Support\Collection
     */
    public function __invoke(Order $order)
    {
        return Comment::whereIn('id', function ($query) use ($order) {
            $query->select('comment_id')
                ->from('commentables')
                ->where('commentable_id', $order->id)
                ->where('commentable_type', 'App\\Models\\Order');
        })
        ->where('is_active', true)
        ->orderBy('created_at', 'desc')
        ->get();
    }
}
