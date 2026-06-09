<?php

return [
    'providers' => [
        [
            'key' => 'petroapp',
            'label' => 'PetroApp',
            'type' => 'native',
            'driver_class' => \Fleetbase\FleetOps\Support\FuelProviders\Providers\PetroAppFuelProvider::class,
            'description' => 'PetroApp fuel card bills, vehicles, trips, and station locations.',
            'docs_url' => 'https://service.petroapp.com.sa/',
            'required_fields' => [
                [
                    'name' => 'api_key',
                    'label' => 'API Key',
                    'type' => 'password',
                    'required' => true,
                ],
                [
                    'name' => 'base_url',
                    'label' => 'Base URL',
                    'type' => 'text',
                    'placeholder' => 'https://app-public.staging.petroapp.app/webservice',
                    'required' => false,
                ],
            ],
            'capabilities' => ['vehicles', 'transactions', 'stations', 'trips'],
            'metadata' => [
                'auth_type' => 'ws_sk_header',
                'pagination' => 'page',
                'default_currency' => 'SAR',
            ],
        ],
    ],
];
