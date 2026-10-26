<?php

declare(strict_types=1);

return ['routes' => [
    ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'setup#tables', 'url' => '/api/tables', 'verb' => 'GET'],
    ['name' => 'setup#createTable', 'url' => '/api/tables', 'verb' => 'POST'],
    ['name' => 'setup#table', 'url' => '/api/tables/{tableId}', 'verb' => 'GET', 'requirements' => ['tableId' => '\\d+']],
    ['name' => 'setup#groups', 'url' => '/api/groups', 'verb' => 'GET'],
    ['name' => 'setup#plans', 'url' => '/api/plans', 'verb' => 'GET'],
    ['name' => 'setup#createPlan', 'url' => '/api/plans', 'verb' => 'POST'],
    ['name' => 'setup#plan', 'url' => '/api/plans/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
    ['name' => 'setup#updatePlan', 'url' => '/api/plans/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
    ['name' => 'setup#deletePlan', 'url' => '/api/plans/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
    ['name' => 'setup#validate', 'url' => '/api/setup/validate', 'verb' => 'POST'],
    ['name' => 'setup#preview', 'url' => '/api/setup/preview', 'verb' => 'POST'],
]];
