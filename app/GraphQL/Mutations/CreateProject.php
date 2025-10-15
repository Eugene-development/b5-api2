<?php

namespace App\GraphQL\Mutations;

use App\Models\Project;
use App\Models\ProjectStatus;

final class CreateProject
{
    /**
     * Create a new project with default status.
     */
    public function __invoke($_, array $args)
    {
        // Get default project status
        $defaultStatus = ProjectStatus::getDefault();

        // Add status_id to the input if not provided
        if (!isset($args['status_id']) && $defaultStatus) {
            $args['status_id'] = $defaultStatus->id;
        }

        // Create and return the project
        return Project::create($args);
    }
}
