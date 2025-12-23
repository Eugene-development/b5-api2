<?php

namespace App\GraphQL\Mutations;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\ProjectStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class UpdateProject
{
    /**
     * Update a project and handle status changes.
     * When status changes to "Принят куратором" (curator-processing),
     * automatically creates a project_user relationship with curator role.
     */
    public function __invoke($_, array $args)
    {
        $projectId = (string) $args['id'];

        Log::info('UpdateProject: Mutation called', [
            'project_id' => $projectId,
            'args' => $args,
        ]);

        // Find the project
        $project = Project::find($projectId);
        if (!$project) {
            Log::error('UpdateProject: Project not found', ['project_id' => $projectId]);
            throw new \Exception("Project not found with ID: {$projectId}");
        }

        $oldStatusId = $project->status_id;
        $newStatusId = isset($args['status_id']) ? (string) $args['status_id'] : null;

        // Check if status is changing
        $statusChanging = $newStatusId && $newStatusId !== $oldStatusId;

        // Update project fields
        $fillableFields = ['value', 'user_id', 'client_id', 'status_id', 'region', 'description',
                          'is_active', 'is_incognito', 'contract_name', 'contract_date',
                          'contract_amount', 'agent_percentage', 'planned_completion_date'];

        foreach ($fillableFields as $field) {
            if (array_key_exists($field, $args)) {
                // Handle address/region mapping
                if ($field === 'region') {
                    $project->address = $args[$field];
                } else {
                    $project->{$field} = $args[$field];
                }
            }
        }

        $project->save();

        Log::info('UpdateProject: Project updated', [
            'project_id' => $project->id,
            'old_status_id' => $oldStatusId,
            'new_status_id' => $newStatusId,
            'status_changing' => $statusChanging,
        ]);

        // If status is changing, check if we need to create curator relationship
        if ($statusChanging && $newStatusId) {
            $this->handleStatusChange($project, $oldStatusId, $newStatusId);
        }

        // Reload project with relationships
        return $project->fresh(['status', 'agent', 'client', 'users', 'curator', 'projectUsers']);
    }

    /**
     * Handle status change logic
     */
    private function handleStatusChange(Project $project, ?string $oldStatusId, string $newStatusId): void
    {
        // Get the new status to check its slug
        $newStatus = ProjectStatus::find($newStatusId);
        if (!$newStatus) {
            Log::warning('UpdateProject: New status not found', ['status_id' => $newStatusId]);
            return;
        }

        Log::info('UpdateProject: Handling status change', [
            'project_id' => $project->id,
            'new_status_slug' => $newStatus->slug,
        ]);

        // If changing to "Принят куратором" (curator-processing), create curator relationship
        if ($newStatus->slug === 'curator-processing') {
            $this->assignCurator($project);
        }
    }

    /**
     * Assign current user as curator to the project
     */
    private function assignCurator(Project $project): void
    {
        // Get current authenticated user
        $currentUser = Auth::user();

        if (!$currentUser) {
            Log::warning('UpdateProject: No authenticated user for curator assignment', [
                'project_id' => $project->id,
            ]);
            return;
        }

        $userId = $currentUser->id;

        Log::info('UpdateProject: Assigning curator', [
            'project_id' => $project->id,
            'user_id' => $userId,
            'user_email' => $currentUser->email,
            'user_status_id' => $currentUser->status_id,
        ]);

        // Check if curator relationship already exists
        $existingCurator = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $userId)
            ->where('role', ProjectUser::ROLE_CURATOR)
            ->first();

        if ($existingCurator) {
            Log::info('UpdateProject: Curator relationship already exists', [
                'project_user_id' => $existingCurator->id,
            ]);
            return;
        }

        // Create new curator relationship
        $projectUser = new ProjectUser();
        $projectUser->id = (string) Str::ulid();
        $projectUser->user_id = $userId;
        $projectUser->project_id = $project->id;
        $projectUser->role = ProjectUser::ROLE_CURATOR;
        $projectUser->save();

        Log::info('UpdateProject: Curator assigned successfully', [
            'project_user_id' => $projectUser->id,
            'user_id' => $userId,
            'project_id' => $project->id,
            'role' => ProjectUser::ROLE_CURATOR,
        ]);
    }
}
