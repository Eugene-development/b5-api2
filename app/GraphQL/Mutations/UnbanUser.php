<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use GraphQL\Error\Error;

final readonly class UnbanUser
{
    /** @param  array{id: string}  $args */
    public function __invoke(null $_, array $args): User
    {
        $user = User::find($args['id']);

        if (!$user) {
            throw new Error('User not found');
        }

        if ($user->isActive()) {
            throw new Error('User is not banned');
        }

        $user->unban();

        return $user->fresh();
    }
}
