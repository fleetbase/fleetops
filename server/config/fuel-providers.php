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
                    'label' => 'API Token',
                    'type' => 'password',
                    'help_text' => 'Bearer API token from PetroApp.',
                    'required' => true,
                ],
            ],
            'capabilities' => ['vehicles', 'transactions', 'stations', 'trips'],
            'sync_defaults' => [
                'window_days' => 7,
                'matching_order' => ['plate_number', 'internal_id', 'vin', 'serial_number', 'call_sign', 'fuel_card_number', 'trip_number'],
                'auto_create_fuel_reports' => true,
            ],
            'setup_instructions' => [
                'Create or request a PetroApp API token.',
                'Enter the token, then run Test Connection.',
                'Run a date-window sync to import bills into Fuel Transactions.',
            ],
            'metadata' => [
                'auth_type' => 'bearer_token',
                'legacy_auth_type' => 'ws_sk_header',
                'pagination' => 'page',
                'default_currency' => 'SAR',
            ],
        ],
    ],
];
