<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class GraphQLAuthenticationTest extends TestCase
{
    public function test_graphql_rejects_an_unauthenticated_request(): void
    {
        $response = $this->postJson('/graphql', [
            'query' => '{ projectStatuses { id } }',
        ]);

        $response->assertUnauthorized();
    }
}
