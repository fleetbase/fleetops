<?php

return [
    'providers' => [
        [
            'key' => 'flespi',
            'label' => 'Flespi',
            'type' => 'native',
            'driver_class' => \Fleetbase\FleetOps\Support\Telematics\Providers\FlespiProvider::class,
            'icon' => 'https://flespi.com/favicon.ico',
            'description' => 'Flespi is a robust telematics platform offering device management, data processing, and API integration.',
            'docs_url' => 'https://flespi.com/docs',
            'required_fields' => [
                [
                    'name' => 'token',
                    'label' => 'Flespi Token',
                    'type' => 'password',
                    'placeholder' => 'Enter your Flespi API token',
                    'required' => true,
                    'validation' => 'required|string|min:20',
                ],
                [
                    'name' => 'webhook_secret',
                    'label' => 'Webhook Secret (Optional)',
                    'type' => 'password',
                    'placeholder' => 'Enter webhook secret for signature validation',
                    'required' => false,
                ],
            ],
            'supports_webhooks' => true,
            'supports_discovery' => true,
            'metadata' => [
                'rate_limit' => 100,
                'pagination' => 'offset',
            ],
        ],

        [
            'key' => 'geotab',
            'label' => 'Geotab',
            'type' => 'native',
            'driver_class' => \Fleetbase\FleetOps\Support\Telematics\Providers\GeotabProvider::class,
            'icon' => 'https://www.geotab.com/favicon.ico',
            'description' => 'Geotab provides fleet management solutions with GPS tracking, driver safety, and compliance features.',
            'docs_url' => 'https://developers.geotab.com/',
            'required_fields' => [
                [
                    'name' => 'database',
                    'label' => 'Database Name',
                    'type' => 'text',
                    'placeholder' => 'Enter your Geotab database name',
                    'required' => true,
                ],
                [
                    'name' => 'username',
                    'label' => 'Username',
                    'type' => 'text',
                    'placeholder' => 'Enter your Geotab username',
                    'required' => true,
                ],
                [
                    'name' => 'password',
                    'label' => 'Password',
                    'type' => 'password',
                    'placeholder' => 'Enter your Geotab password',
                    'required' => true,
                ],
            ],
            'supports_webhooks' => false,
            'supports_discovery' => true,
            'metadata' => [
                'rate_limit' => 50,
                'auth_type' => 'session',
            ],
        ],

        [
            'key' => 'samsara',
            'label' => 'Samsara',
            'type' => 'native',
            'driver_class' => \Fleetbase\FleetOps\Support\Telematics\Providers\SamsaraProvider::class,
            'icon' => 'https://www.samsara.com/favicon.ico',
            'description' => 'Samsara offers IoT solutions for fleet operations, including GPS tracking, dashcams, and asset monitoring.',
            'docs_url' => 'https://developers.samsara.com/',
            'required_fields' => [
                [
                    'name' => 'api_token',
                    'label' => 'API Token',
                    'type' => 'password',
                    'placeholder' => 'Enter your Samsara API token',
                    'required' => true,
                    'validation' => 'required|string|min:20',
                ],
                [
                    'name' => 'webhook_secret',
                    'label' => 'Webhook Secret (Optional)',
                    'type' => 'password',
                    'placeholder' => 'Enter webhook secret for signature validation',
                    'required' => false,
                ],
            ],
            'supports_webhooks' => true,
            'supports_discovery' => true,
            'metadata' => [
                'rate_limit' => 60,
                'pagination' => 'cursor',
            ],
        ],
    ]
];

