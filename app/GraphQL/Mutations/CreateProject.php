<?php

namespace App\GraphQL\Mutations;

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

final class CreateProject
{
    /**
     * Create a new project with default status.
     * Automatically creates project_user relationship with agent role.
     */
    public function __invoke($_, array $args)
    {
        // Get default project status
        $defaultStatus = ProjectStatus::getDefault();

        // Add status_id to the input if not provided
        if (!isset($args['status_id']) && $defaultStatus) {
            $args['status_id'] = $defaultStatus->id;
        }

        // Create the project
        $project = Project::create($args);

        Log::info('CreateProject: Project created', [
            'project_id' => $project->id,
            'user_id' => $args['user_id'] ?? null,
        ]);

        // If user_id is provided, create project_user relationship with agent role
        if (!empty($args['user_id'])) {
            $userId = (int) $args['user_id'];
            $user = User::find($userId);

            if ($user) {
                $projectUser = new ProjectUser();
                $projectUser->id = (string) Str::ulid();
                $projectUser->user_id = $userId;
                $projectUser->project_id = $project->id;
                $projectUser->role = ProjectUser::ROLE_AGENT;
                $projectUser->save();

                Log::info('CreateProject: Created agent relationship', [
                    'project_user_id' => $projectUser->id,
                    'user_id' => $userId,
                    'project_id' => $project->id,
                    'role' => ProjectUser::ROLE_AGENT,
                ]);
            }
        }

        return $project;
    }
}
