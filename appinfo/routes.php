<?php

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#approvals', 'url' => '/approvals', 'verb' => 'GET'],
        ['name' => 'pdf#download', 'url' => '/pdf', 'verb' => 'GET'],
        ['name' => 'approval#approve', 'url' => '/approve/{id}', 'verb' => 'POST'],
        ['name' => 'approval#approve_open_year', 'url' => '/approve-open-year', 'verb' => 'POST'],
        ['name' => 'approval#reject', 'url' => '/reject/{id}', 'verb' => 'POST'],
        ['name' => 'approval#confirm_cancellation', 'url' => '/cancel/{id}', 'verb' => 'POST'],
        ['name' => 'approval#keep_booking', 'url' => '/keep/{id}', 'verb' => 'POST'],
        ['name' => 'carryover#save', 'url' => '/carryover', 'verb' => 'POST'],
        ['name' => 'admin_settings#save', 'url' => '/settings/admin', 'verb' => 'POST'],
    ],
];
