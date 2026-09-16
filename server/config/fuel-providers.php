<?php

return [
    'providers' => [
        [
            'key' => 'petroapp',
            'label' => 'PetroApp',
            'type' => 'native',
            'category' => 'Fuel card integration',
            'icon' => 'gas-pump',
            'driver_class' => \Fleetbase\FleetOps\Support\FuelProviders\Providers\PetroAppFuelProvider::class,
            'description' => 'PetroApp fuel card bills, vehicles, trips, and station locations.',
            'docs_url' => 'https://service.petroapp.com.sa/',
            'required_fields' => [
                [
                    'name' => 'api_token',
                    'label' => 'Integration Token (WS-SK) or API Token',
                    'type' => 'password',
                    'help_text' => 'Use the test token for Sandbox, or your company token for Production. Select the matching authentication method.',
                    'required' => true,
                ],
                [
                    'name' => 'auth_type',
                    'label' => 'Authentication method',
                    'type' => 'select',
                    'default' => 'ws_sk_header',
                    'options' => [
                        ['value' => 'ws_sk_header', 'label' => 'Integration Token (WS-SK)'],
                        ['value' => 'bearer_token', 'label' => 'Bearer API Token'],
                    ],
                    'help_text' => 'Choose WS-SK for an integration token, or Bearer for a token returned by get_apiKey.',
                    'required' => false,
                ],
                [
                    'name' => 'base_url',
                    'label' => 'Base URL override (optional)',
                    'type' => 'url',
                    'help_text' => 'Leave blank to use the selected environment. A custom URL overrides the environment selection.',
                    'required' => false,
                ],
            ],
            'capabilities' => ['vehicles', 'transactions', 'stations', 'trips'],
            'sync_defaults' => [
                'window_days' => 7,
                'matching_order' => ['plate_number', 'internal_id', 'vin', 'serial_number', 'call_sign', 'fuel_card_number', 'trip_number'],
                'auto_create_fuel_reports' => true,
            ],
            'setup_instructions' => [
                'Select Sandbox for PetroApp staging and enter the test integration token using WS-SK authentication.',
                'Run Test Connection, then sync a small date window to verify imported bills and vehicle matches.',
                'For Production, use your company integration token from the PetroApp API token profile page.',
                'Alternatively, exchange company username and password at get_apiKey and use the returned Bearer API token.',
            ],
            'metadata' => [
                'auth_type' => 'ws_sk_header',
                'supported_auth_types' => ['ws_sk_header', 'bearer_token'],
                'base_urls' => \Fleetbase\FleetOps\Support\FuelProviders\Providers\PetroAppFuelProvider::BASE_URLS,
                'pagination' => 'page',
                'default_currency' => 'SAR',
            ],
        ],
    ],
];
