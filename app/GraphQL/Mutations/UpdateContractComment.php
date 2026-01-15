<?php

namespace App\GraphQL\Mutations;

use App\Models\Comment;

class UpdateContractComment
{
    /**
     * Update a contract comment.
     *
     * @param null $_
     * @param array $args
     * @return Comment
     */
    public function __invoke($_, array $args)
    {
        $comment = Comment::findOrFail($args['id']);

        $comment->update([
            'value' => $args['value'],
        ]);

        return $comment;
    }
}
