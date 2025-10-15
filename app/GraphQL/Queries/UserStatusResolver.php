<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\User;

final readonly class UserStatusResolver
{
    public function resolve(User $user): string
    {
        return $user->ban ? 'banned' : 'active';
    }
}
