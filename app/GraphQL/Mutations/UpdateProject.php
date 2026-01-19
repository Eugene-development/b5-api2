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
        } else {
            // Even if status is not changing, check if curator needs to be assigned
            // This handles the case when status was set before the curator logic was implemented
            $this->ensureCuratorAssigned($project);
        }

        // Reload project with relationships
        return $project->fresh(['status', 'agent', 'client', 'users', 'curator', 'projectUsers']);
    }

    /**
     * Ensure curator is assigned if project has curator-processing status but no curator
     */
    private function ensureCuratorAssigned(Project $project): void
    {
        // Get current status
        $status = $project->status;
        if (!$status || $status->slug !== 'curator-processing') {
            return;
        }

        // Check if curator already exists
        $existingCurator = ProjectUser::where('project_id', $project->id)
            ->where('role', ProjectUser::ROLE_CURATOR)
            ->first();

        if ($existingCurator) {
            return; // Curator already assigned
        }

        Log::info('UpdateProject: Project has curator-processing status but no curator, assigning...', [
            'project_id' => $project->id,
        ]);

        // Assign current user as curator and create bonuses
        $this->assignCuratorAndCreateBonuses($project);
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

        // Get the old status slug
        $oldStatusSlug = null;
        if ($oldStatusId) {
            $oldStatus = ProjectStatus::find($oldStatusId);
            $oldStatusSlug = $oldStatus?->slug;
        }

        Log::info('UpdateProject: Handling status change', [
            'project_id' => $project->id,
            'old_status_slug' => $oldStatusSlug,
            'new_status_slug' => $newStatus->slug,
        ]);

        // If changing to "Новый проект" (new-project) from "Принят куратором" (curator-processing)
        // Remove curator relationship and curator bonuses
        if ($newStatus->slug === 'new-project' && $oldStatusSlug === 'curator-processing') {
            $this->removeCuratorAndBonuses($project);
        }

        // If changing to "Принят куратором" (curator-processing), create curator relationship and bonuses
        if ($newStatus->slug === 'curator-processing') {
            $this->assignCuratorAndCreateBonuses($project);
        }

        // Статус, при котором бонусы аннулируются
        $cancellingStatus = 'client-refused';

        // If changing to "Отказ" (client-refused), cancel all bonuses
        if ($newStatus->slug === $cancellingStatus) {
            $this->cancelProjectBonuses($project);
            return;
        }

        // If changing FROM "Отказ" to another status, restore bonuses
        if ($oldStatusSlug === $cancellingStatus && $newStatus->slug !== $cancellingStatus) {
            $this->restoreProjectBonuses($project);
        }
    }

    /**
     * Remove curator relationship and curator bonuses from the project.
     * Called when project status changes from "Принят куратором" to "Новый проект".
     */
    private function removeCuratorAndBonuses(Project $project): void
    {
        $bonusService = app(\App\Services\BonusService::class);
        
        // Remove curator bonuses first
        $removedBonusesCount = $bonusService->removeCuratorBonusesForProject($project->id);
        
        // Remove curator relationship(s)
        $removedCuratorsCount = ProjectUser::where('project_id', $project->id)
            ->where('role', ProjectUser::ROLE_CURATOR)
            ->delete();
        
        Log::info('UpdateProject: Removed curator and bonuses from project', [
            'project_id' => $project->id,
            'removed_curators_count' => $removedCuratorsCount,
            'removed_bonuses_count' => $removedBonusesCount,
        ]);
    }

    /**
     * Assign current user as curator and create bonuses for all existing contracts and orders.
     * Called when project status changes to "Принят куратором".
     */
    private function assignCuratorAndCreateBonuses(Project $project): void
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

        // First, remove any existing curator relationships for this project
        // This ensures we have a clean state before assigning a new curator
        $existingCurator = ProjectUser::where('project_id', $project->id)
            ->where('role', ProjectUser::ROLE_CURATOR)
            ->first();

        if ($existingCurator) {
            // If the same curator is already assigned, just ensure bonuses exist
            if ($existingCurator->user_id == $userId) {
                Log::info('UpdateProject: Curator already assigned, ensuring bonuses exist', [
                    'project_id' => $project->id,
                    'curator_id' => $userId,
                ]);
            } else {
                // Different curator - remove old one first
                $bonusService = app(\App\Services\BonusService::class);
                $bonusService->removeCuratorBonusesForProject($project->id);
                $existingCurator->delete();
                
                Log::info('UpdateProject: Removed previous curator', [
                    'project_id' => $project->id,
                    'old_curator_id' => $existingCurator->user_id,
                    'new_curator_id' => $userId,
                ]);
            }
        }

        // Create or ensure curator relationship exists
        $curatorRelation = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $userId)
            ->where('role', ProjectUser::ROLE_CURATOR)
            ->first();

        if (!$curatorRelation) {
            $projectUser = new ProjectUser();
            $projectUser->id = (string) \Illuminate\Support\Str::ulid();
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

        // Create curator bonuses for all existing contracts and orders
        $bonusService = app(\App\Services\BonusService::class);
        $createdBonusesCount = $bonusService->createCuratorBonusesForProject($project->id, $userId);

        Log::info('UpdateProject: Created curator bonuses for project', [
            'project_id' => $project->id,
            'curator_id' => $userId,
            'created_bonuses_count' => $createdBonusesCount,
        ]);
    }


    /**
     * Cancel all unpaid bonuses for the project
     */
    private function cancelProjectBonuses(Project $project): void
    {
        $bonusService = app(\App\Services\BonusService::class);
        $cancelledCount = $bonusService->cancelBonusesForProject($project->id);

        Log::info('UpdateProject: Cancelled project bonuses', [
            'project_id' => $project->id,
            'cancelled_count' => $cancelledCount,
        ]);
    }

    /**
     * Restore cancelled bonuses for the project
     */
    private function restoreProjectBonuses(Project $project): void
    {
        $bonusService = app(\App\Services\BonusService::class);
        $restoredCount = $bonusService->restoreBonusesForProject($project->id);

        Log::info('UpdateProject: Restored project bonuses', [
            'project_id' => $project->id,
            'restored_count' => $restoredCount,
        ]);
    }
}
