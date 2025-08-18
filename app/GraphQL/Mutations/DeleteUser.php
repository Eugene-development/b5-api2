<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use GraphQL\Error\Error;

final readonly class DeleteUser
{
    /** @param  array{id: string}  $args */
    public function __invoke(null $_, array $args): array
    {
        $user = User::find($args['id']);

        if (!$user) {
            throw new Error('User not found');
        }

        // Store user info before deletion for response
        $deletedUser = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'deleted' => true
        ];

        // Permanently delete the user
        $user->delete();

        return $deletedUser;
    }
}
