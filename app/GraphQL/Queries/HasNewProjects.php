<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Project;

final readonly class HasNewProjects
{
    /**
     * Check if there are any projects with "Новый проект" status.
     *
     * @return bool
     */
    public function __invoke(): bool
    {
        // Check if there exists at least one project with status_id = 01K7HRKTSQV1894Y3JD9WV5KZX
        return Project::where('status_id', '01K7HRKTSQV1894Y3JD9WV5KZX')->exists();
    }
}
