<?php

/**
 * Test GraphQL projects query
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Artisan;

echo "Testing GraphQL projects query...\n\n";

// Simulate GraphQL query
$query = <<<'GQL'
query GetProjects {
    projects(first: 10) {
        data {
            id
            value
            name
            contract_number
        }
    }
}
GQL;

echo "Query:\n";
echo $query . "\n\n";

// Execute using lighthouse
try {
    $result = \Nuwave\Lighthouse\Testing\TestingSchema::make(graphql: $query);

    echo "Result:\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
