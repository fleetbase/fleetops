<?php

/**
 * -------------------------------------------
 * Fleetbase Core API Configuration
 * -------------------------------------------
 */
return [
    'api' => [
        'version' => '0.0.1',
        'routing' => [
            'prefix' => null,
            'internal_prefix' => 'int'
        ]
    ],
    'navigator' => [
        'bypass_verification_code' => env('NAVIGATOR_BYPASS_VERIFICATION_CODE', '999000')
    ],
    'connection' => [
        'db' => env('DB_CONNECTION', 'mysql')
    ]
];
