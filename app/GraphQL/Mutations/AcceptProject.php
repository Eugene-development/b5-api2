<?php

namespace App\GraphQL\Mutations;

use App\Models\ProjectUser;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AcceptProject
{
    /**
     * Link user to a project and update project status.
     */
    public function __invoke($_, array $args)
    {
        // Ensure correct types: userId is integer, projectId and statusId are ULID strings
        $projectId = (string) $args['projectId'];
        $userId = (int) $args['userId'];
        $statusId = isset($args['statusId']) ? (string) $args['statusId'] : null;

        Log::info('AcceptProject: Mutation called', [
            'project_id' => $projectId,
            'project_id_type' => gettype($projectId),
            'user_id' => $userId,
            'user_id_type' => gettype($userId),
            'status_id' => $statusId,
            'status_id_type' => gettype($statusId),
            'request_origin' => request()->header('Origin'),
            'request_method' => request()->method(),
        ]);

        // Verify project exists (ULID)
        $project = Project::find($projectId);
        if (!$project) {
            Log::error('AcceptProject: Project not found', [
                'project_id' => $projectId,
                'project_id_type' => gettype($projectId)
            ]);
            throw new \Exception("Project not found with ID: {$projectId}");
        }

        // Verify user exists (integer ID)
        $user = \App\Models\User::find($userId);
        if (!$user) {
            Log::error('AcceptProject: User not found', [
                'user_id' => $userId,
                'user_id_type' => gettype($userId)
            ]);
            throw new \Exception("User not found with ID: {$userId}");
        }

        Log::info('AcceptProject: Attempting to link user to project', [
            'user_id' => $userId,
            'project_id' => $projectId
        ]);

        // Check if the relationship already exists
        $existing = ProjectUser::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->first();

        if ($existing) {
            Log::info('AcceptProject: Relationship already exists', ['id' => $existing->id]);
            return $existing;
        }

        // Create new relationship with explicit ULID
        $projectUser = new ProjectUser();
        $projectUser->id = (string) Str::ulid();
        $projectUser->user_id = $userId;
        $projectUser->project_id = $projectId;
        $projectUser->save();

        Log::info('AcceptProject: Created relationship', [
            'id' => $projectUser->id,
            'user_id' => $projectUser->user_id,
            'project_id' => $projectUser->project_id,
        ]);

        // Update project status (ULID)
        if ($statusId) {
            // Verify status exists
            $status = \App\Models\ProjectStatus::find($statusId);
            if (!$status) {
                Log::error('AcceptProject: Status not found', [
                    'status_id' => $statusId,
                    'status_id_type' => gettype($statusId)
                ]);
                throw new \Exception("Project status not found with ID: {$statusId}");
            }

            $project->status_id = $statusId;
            $project->save();

            Log::info('AcceptProject: Updated project status', [
                'project_id' => $project->id,
                'old_status_id' => $project->getOriginal('status_id'),
                'new_status_id' => $statusId
            ]);
        } else {
            Log::warning('AcceptProject: No statusId provided, project status not changed', [
                'project_id' => $project->id,
                'current_status_id' => $project->status_id
            ]);
        }

        return $projectUser;
    }
}
