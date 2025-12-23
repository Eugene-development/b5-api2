<?php

namespace App\GraphQL\Mutations;

use App\Models\ProjectUser;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AcceptProject
{
    /**
     * Link user to a project with a specific role and update project status.
     *
     * При смене статуса на "Принят куратором" автоматически определяется роль
     * пользователя на основе его user_status (статуса в системе).
     */
    public function __invoke($_, array $args)
    {
        // Ensure correct types: userId is integer, projectId and statusId are ULID strings
        $projectId = (string) $args['projectId'];
        $userId = (int) $args['userId'];
        $statusId = isset($args['statusId']) ? (string) $args['statusId'] : null;
        $role = isset($args['role']) ? (string) $args['role'] : null;

        Log::info('AcceptProject: Mutation called', [
            'project_id' => $projectId,
            'project_id_type' => gettype($projectId),
            'user_id' => $userId,
            'user_id_type' => gettype($userId),
            'status_id' => $statusId,
            'status_id_type' => gettype($statusId),
            'role' => $role,
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
        $user = User::with('status')->find($userId);
        if (!$user) {
            Log::error('AcceptProject: User not found', [
                'user_id' => $userId,
                'user_id_type' => gettype($userId)
            ]);
            throw new \Exception("User not found with ID: {$userId}");
        }

        // Определяем роль пользователя в проекте на основе его статуса в системе
        if (!$role) {
            $role = $this->determineRoleFromUserStatus($user);
        }

        // Валидация роли
        if (!ProjectUser::isValidRole($role)) {
            Log::error('AcceptProject: Invalid role', ['role' => $role]);
            throw new \Exception("Invalid role: {$role}");
        }

        Log::info('AcceptProject: Attempting to link user to project', [
            'user_id' => $userId,
            'project_id' => $projectId,
            'role' => $role,
            'user_status_slug' => $user->status?->slug
        ]);

        // Check if the relationship already exists with this role
        $existing = ProjectUser::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('role', $role)
            ->first();

        if ($existing) {
            Log::info('AcceptProject: Relationship already exists', ['id' => $existing->id]);
            return $existing;
        }

        // Create new relationship with explicit ULID and role
        $projectUser = new ProjectUser();
        $projectUser->id = (string) Str::ulid();
        $projectUser->user_id = $userId;
        $projectUser->project_id = $projectId;
        $projectUser->role = $role;
        $projectUser->save();

        Log::info('AcceptProject: Created relationship', [
            'id' => $projectUser->id,
            'user_id' => $projectUser->user_id,
            'project_id' => $projectUser->project_id,
            'role' => $projectUser->role,
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

    /**
     * Определяет роль пользователя в проекте на основе его статуса в системе (user_status)
     */
    private function determineRoleFromUserStatus(User $user): string
    {
        $userStatusSlug = $user->status?->slug;

        return match ($userStatusSlug) {
            'curator' => ProjectUser::ROLE_CURATOR,
            'designer' => ProjectUser::ROLE_DESIGNER,
            'manager' => ProjectUser::ROLE_MANAGER,
            'agent' => ProjectUser::ROLE_AGENT,
            default => ProjectUser::ROLE_AGENT, // По умолчанию - агент
        };
    }
}
