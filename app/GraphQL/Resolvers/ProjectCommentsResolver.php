<?php

namespace App\GraphQL\Resolvers;

use App\Models\Project;
use App\Models\Comment;
use Illuminate\Support\Facades\DB;

class ProjectCommentsResolver
{
    /**
     * Get comments for a project via commentables pivot table.
     *
     * @param Project $project
     * @return \Illuminate\Support\Collection
     */
    public function __invoke(Project $project)
    {
        return Comment::whereIn('id', function ($query) use ($project) {
            $query->select('comment_id')
                ->from('commentables')
                ->where('commentable_id', $project->id)
                ->where('commentable_type', 'App\\Models\\Project');
        })
        ->where('is_active', true)
        ->orderBy('created_at', 'desc')
        ->get();
    }
}
