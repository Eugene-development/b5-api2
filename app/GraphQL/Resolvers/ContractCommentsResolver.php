<?php

namespace App\GraphQL\Resolvers;

use App\Models\Contract;
use App\Models\Comment;

class ContractCommentsResolver
{
    /**
     * Get comments for a contract via commentables pivot table.
     *
     * @param Contract $contract
     * @return \Illuminate\Support\Collection
     */
    public function __invoke(Contract $contract)
    {
        return Comment::whereIn('id', function ($query) use ($contract) {
            $query->select('comment_id')
                ->from('commentables')
                ->where('commentable_id', $contract->id)
                ->where('commentable_type', 'App\\Models\\Contract');
        })
        ->where('is_active', true)
        ->orderBy('created_at', 'desc')
        ->get();
    }
}

