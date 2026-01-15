<?php

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\Models\Comment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class AddOrderComment
{
    /**
     * Add a comment to an order.
     *
     * @param null $_
     * @param array $args
     * @return Comment
     */
    public function __invoke($_, array $args)
    {
        $order = Order::findOrFail($args['order_id']);
        $user = Auth::user();

        return DB::transaction(function () use ($order, $args, $user) {
            // Create the comment
            $comment = Comment::create([
                'value' => $args['value'],
                'author_id' => $user?->id,
                'author_name' => $user?->name ?? $args['author_name'] ?? null,
                'is_active' => true,
                'is_approved' => true,
                'approved_at' => now(),
            ]);

            // Link comment to order via pivot table
            DB::table('commentables')->insert([
                'comment_id' => $comment->id,
                'commentable_id' => $order->id,
                'commentable_type' => 'App\\Models\\Order',
                'sort_order' => 0,
                'is_pinned' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $comment;
        });
    }
}
