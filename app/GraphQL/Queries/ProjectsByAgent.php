<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class ProjectsByAgent
{
    /** @return Collection<int, Project> */
    public function __invoke(mixed $_, array $args, GraphQLContext $context): Collection
    {
        $user = $context->user();
        $requestedUserId = (int) $args['user_id'];
        $staffStatuses = ['admin', 'curator', 'manager', 'designer'];

        if (
            ! $user
            || ((int) $user->id !== $requestedUserId
                && ! in_array($user->status?->slug, $staffStatuses, true))
        ) {
            throw new AuthorizationException('Нельзя запрашивать проекты другого агента.');
        }

        return Project::query()
            ->where('user_id', $requestedUserId)
            ->get();
    }
}
