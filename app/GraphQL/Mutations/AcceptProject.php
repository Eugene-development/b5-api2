<?php

namespace App\GraphQL\Mutations;

use App\Models\ProjectUser;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AcceptProject
{
    /**
     * Link user to a project and update project status.
     */
    public function __invoke($_, array $args)
    {
        $projectId = $args['projectId'];
        $userId = $args['userId'];

        // Verify project exists
        $project = Project::find($projectId);
        if (!$project) {
            Log::error('AcceptProject: Project not found', ['project_id' => $projectId]);
            throw new \Exception('Project not found');
        }

        // Verify user exists
        $user = \App\Models\User::find($userId);
        if (!$user) {
            Log::error('AcceptProject: User not found', ['user_id' => $userId]);
            throw new \Exception('User not found');
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

        // Find "Принят куратором" status
        $curatorAcceptedStatus = \App\Models\ProjectStatus::where('slug', 'curator-processing')->first();
        if (!$curatorAcceptedStatus) {
            Log::error('AcceptProject: Curator accepted status not found');
            throw new \Exception('Curator accepted status not found');
        }

        // Start database transaction
        \DB::beginTransaction();

        try {
            // Create new relationship with explicit ULID
            $projectUser = new ProjectUser();
            $projectUser->id = (string) Str::ulid();
            $projectUser->user_id = $userId;
            $projectUser->project_id = $projectId;
            $projectUser->save();

            // Update project status to "Принят куратором"
            $project->status_id = $curatorAcceptedStatus->id;
            $project->save();

            \DB::commit();

            Log::info('AcceptProject: Successfully created relationship and updated status', [
                'id' => $projectUser->id,
                'user_id' => $projectUser->user_id,
                'project_id' => $projectUser->project_id,
                'new_status_id' => $curatorAcceptedStatus->id,
                'new_status' => $curatorAcceptedStatus->value
            ]);

            return $projectUser;
        } catch (\Exception $e) {
            \DB::rollback();
            Log::error('AcceptProject: Transaction failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
