<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use GraphQL\Error\Error;

final readonly class BanUser
{
    /** @param  array{id: string}  $args */
    public function __invoke(null $_, array $args): User
    {
        $user = User::find($args['id']);

        if (!$user) {
            throw new Error('User not found');
        }

        if ($user->isBanned()) {
            throw new Error('User is already banned');
        }

        $user->ban();

        return $user->fresh();
    }
}
