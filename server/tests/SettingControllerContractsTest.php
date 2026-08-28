<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SettingController;
use Illuminate\Http\Request;

class FleetOpsSettingControllerProbe extends SettingController
{
    public array $configured            = [];
    public array $configuredCompany     = [];
    public array $settingValues         = [];
    public array $settings              = [];
    public array $companySettings       = [];
    public array $lookupCompanySettings = [];
    public mixed $company               = null;
    public array $notifiables           = [];
    public array $notifications         = [];
    public array $providers             = [];
    public string $googleMapsApiKey     = '';

    protected function configureSetting(string $key, mixed $value): mixed
    {
        $this->configured[$key] = $value;
        $this->settings[$key]   = $value;

        return null;
    }

    protected function configureCompanySetting(string $key, mixed $value): mixed
    {
        $this->configuredCompany[$key]     = $value;
        $this->companySettings[$key]       = $value;
        $this->lookupCompanySettings[$key] = $value;

        return null;
    }

    protected function settingValue(string $key): mixed
    {
        return $this->settingValues[$key] ?? null;
    }

    protected function lookupSetting(string $key, mixed $defaultValue = null): mixed
    {
        return $this->settings[$key] ?? $defaultValue;
    }

    protected function lookupFromCompanySetting(string $key, mixed $defaultValue = null): mixed
    {
        return $this->companySettings[$key] ?? $defaultValue;
    }

    protected function lookupCompanySetting(string $key, mixed $defaultValue = null): mixed
    {
        return $this->lookupCompanySettings[$key] ?? $defaultValue;
    }

    protected function currentCompany(): mixed
    {
        return $this->company;
    }

    protected function notificationNotifiables(): array
    {
        return $this->notifiables;
    }

    protected function notificationsByPackage(string $package): array
    {
        return $this->notifications[$package] ?? [];
    }

    protected function trackingProviders(): array
    {
        return $this->providers;
    }

    protected function googleMapsApiKey(): string
    {
        return $this->googleMapsApiKey;
    }
}

class FleetOpsTrackingProviderOptionFake
{
    public function __construct(private array $capabilities)
    {
    }

    public function capabilities(): object
    {
        return new class($this->capabilities) {
            public function __construct(private array $capabilities)
            {
            }

            public function toArray(): array
            {
                return $this->capabilities;
            }
        };
    }
}

function fleetopsJsonPayload(mixed $response): array
{
    return $response->getData(true);
}

test('setting controller persists and returns basic company settings through configured keys', function () {
    $controller = new FleetOpsSettingControllerProbe();

    $entityPayload = fleetopsJsonPayload($controller->saveEntityEditingSettings(new Request([
        'entityEditingSettings' => ['orders' => ['editable' => true]],
    ])));
    $controller->settingValues['fleet-ops.entity-editing-settings'] = ['orders' => ['editable' => false]];

    $disabledDriverPayload = fleetopsJsonPayload($controller->savedDriverOnboardSettings(new Request([
        'driverOnboardSettings' => [
            'companyId'                         => 'company-1',
            'enableDriverOnboardFromApp'        => false,
            'driverMustProvideOnboardDoucments' => true,
            'requiredOnboardDocuments'          => ['license'],
            'driverOnboardAppMethod'            => 'invite',
        ],
    ])));
    $controller->settingValues['fleet-ops.driver-onboard-settings.company-1'] = ['enableDriverOnboardFromApp' => true];

    $enabledConfigs = fleetopsJsonPayload($controller->saveCustomerEnabledOrderConfigs(new Request([
        'enabledOrderConfigs' => ['express' => 'order-express', 'freight' => 'order-freight'],
    ])));
    $controller->companySettings['fleet-ops.customer-enabled-order-configs'] = ['same-day', 'bulk'];

    $paymentSave = fleetopsJsonPayload($controller->saveCustomerPortalPaymentConfig(new Request([
        'paymentsConfig' => ['paymentsEnabled' => true, 'provider' => 'stripe'],
    ])));
    $controller->companySettings['fleet-ops.customer-payments-configs'] = ['paymentsEnabled' => true];
    $controller->company                                                = (object) ['stripe_connect_id' => 'acct_123'];

    expect($entityPayload)->toBe(['entityEditingSettings' => ['orders' => ['editable' => true]]])
        ->and($controller->configured['fleet-ops.entity-editing-settings'])->toBe(['orders' => ['editable' => true]])
        ->and(fleetopsJsonPayload($controller->getEntityEditingSettings()))->toBe(['entityEditingSettings' => ['orders' => ['editable' => false]]])
        ->and(fleetopsJsonPayload((new FleetOpsSettingControllerProbe())->getEntityEditingSettings()))->toBe(['entityEditingSettings' => []])
        ->and($disabledDriverPayload['driverOnboardSettings'])->toMatchArray([
            'companyId'                         => 'company-1',
            'enableDriverOnboardFromApp'        => false,
            'driverMustProvideOnboardDoucments' => false,
            'requiredOnboardDocuments'          => [],
            'driverOnboardAppMethod'            => '',
        ])
        ->and($controller->configured['fleet-ops.driver-onboard-settings.company-1'])->toBe($disabledDriverPayload['driverOnboardSettings'])
        ->and(fleetopsJsonPayload($controller->getDriverOnboardSettings('company-1')))->toBe(['driverOnboardSettings' => ['enableDriverOnboardFromApp' => true]])
        ->and(fleetopsJsonPayload((new FleetOpsSettingControllerProbe())->getDriverOnboardSettings('missing')))->toBe(['driverOnboardSettings' => []])
        ->and($enabledConfigs)->toBe(['order-express', 'order-freight'])
        ->and(fleetopsJsonPayload($controller->getCustomerEnabledOrderConfigs()))->toBe(['same-day', 'bulk'])
        ->and($paymentSave)->toBe(['paymentsEnabled' => true, 'provider' => 'stripe'])
        ->and(fleetopsJsonPayload($controller->getCustomerPortalPaymentConfig()))->toBe([
            'paymentsEnabled'            => true,
            'paymentsOnboardCompleted'   => true,
        ]);
});

test('setting controller handles notification and routing settings contracts', function () {
    $controller                                                 = new FleetOpsSettingControllerProbe();
    $controller->notifiables                                    = ['drivers', 'customers'];
    $controller->notifications['fleet-ops']                     = ['order.created'];
    $controller->lookupCompanySettings['notification_settings'] = ['core' => ['email' => true]];
    $controller->lookupCompanySettings['routing']               = [
        'routing_display_engine'      => 'mapbox',
        'routing_optimization_engine' => 'vroom',
    ];

    $notificationSave = fleetopsJsonPayload($controller->saveNotificationSettings(new Request([
        'notificationSettings' => ['fleet-ops' => ['sms' => false]],
    ])));
    $routingSave = fleetopsJsonPayload($controller->saveRoutingSettings(new Request([
        'display_engine'      => 'google',
        'optimization_engine' => 'vroom',
        'unit'                => 'mi',
    ])));

    expect(fleetopsJsonPayload($controller->getNotifiables()))->toBe(['drivers', 'customers'])
        ->and(fleetopsJsonPayload($controller->getNotificationRegistry()))->toBe(['order.created'])
        ->and($notificationSave)->toMatchArray(['status' => 'ok'])
        ->and($controller->configuredCompany['notification_settings'])->toBe([
            'core'      => ['email' => true],
            'fleet-ops' => ['sms' => false],
        ])
        ->and(fleetopsJsonPayload($controller->getNotificationSettings()))->toMatchArray([
            'status'               => 'ok',
            'notificationSettings' => $controller->configuredCompany['notification_settings'],
        ])
        ->and(fn () => $controller->saveNotificationSettings(new Request(['notificationSettings' => 'bad'])))->toThrow(Exception::class, 'Invalid notification settings data.')
        ->and($routingSave)->toMatchArray(['status' => 'ok'])
        ->and($controller->configuredCompany['routing'])->toBe([
            'router'                      => 'google',
            'display_engine'              => 'google',
            'optimization_engine'         => 'vroom',
            'routing_display_engine'      => 'google',
            'routing_optimization_engine' => 'vroom',
            'unit'                        => 'mi',
        ]);

    $controller->lookupCompanySettings['routing'] = [
        'routing_display_engine' => 'mapbox',
    ];

    expect(fleetopsJsonPayload($controller->getRoutingSettings()))->toBe([
        'routing_display_engine'      => 'mapbox',
        'router'                      => 'mapbox',
        'display_engine'              => 'mapbox',
        'optimization_engine'         => 'mapbox',
        'routing_optimization_engine' => 'mapbox',
        'unit'                        => 'km',
    ]);
});

test('setting controller normalizes tracking settings and provider options', function () {
    config()->set('fleetops.tracking', [
        'provider'                  => 'osrm',
        'fallbacks'                 => ['calculated'],
        'traffic_enabled'           => false,
        'cache_ttl_seconds'         => 30,
        'route_cache_ttl_seconds'   => 120,
        'default_vehicle_speed_kph' => 25,
    ]);

    $controller            = new FleetOpsSettingControllerProbe();
    $controller->providers = [
        'osrm'          => new FleetOpsTrackingProviderOptionFake(['routes']),
        'google_routes' => new FleetOpsTrackingProviderOptionFake(['traffic', 'eta']),
    ];
    $controller->settings['fleet-ops.tracking-settings'] = [
        'provider'                         => 'google_routes',
        'fallbacks'                        => 'osrm, calculated',
        'traffic_enabled'                  => true,
        'stale_location_threshold_seconds' => 90,
        'alerts'                           => [
            'route_deviations' => ['enabled' => false, 'distance_threshold_meters' => -1],
        ],
    ];

    $saved = fleetopsJsonPayload($controller->saveTrackingSettings(new Request([
        'provider'                         => 'osrm',
        'fallbacks'                        => 'google_routes, calculated',
        'traffic_enabled'                  => '1',
        'cache_ttl_seconds'                => '75',
        'route_cache_ttl_seconds'          => '900',
        'stale_location_threshold_seconds' => '240',
        'default_vehicle_speed_kph'        => '42.5',
        'alerts'                           => [
            'late_departures' => ['enabled' => false, 'grace_period_minutes' => '-10'],
        ],
    ])));

    expect($saved)->toMatchArray(['status' => 'ok'])
        ->and($controller->configuredCompany['tracking'])->toMatchArray([
            'provider'                         => 'osrm',
            'fallbacks'                        => ['google_routes', 'calculated'],
            'traffic_enabled'                  => true,
            'cache_ttl_seconds'                => 75,
            'route_cache_ttl_seconds'          => 900,
            'stale_location_threshold_seconds' => 240,
            'default_vehicle_speed_kph'        => 42.5,
        ])
        ->and($controller->configuredCompany['tracking']['alerts']['late_departures'])->toBe([
            'enabled'              => false,
            'grace_period_minutes' => 0,
        ]);

    $controller->lookupCompanySettings['tracking'] = [
        'provider'                         => 'google_routes',
        'fallbacks'                        => ['osrm', 'calculated'],
        'traffic_enabled'                  => true,
        'cache_ttl_seconds'                => 60,
        'route_cache_ttl_seconds'          => 600,
        'stale_location_threshold_seconds' => 300,
        'default_vehicle_speed_kph'        => 35,
        'alerts'                           => [
            'prolonged_stoppages' => ['duration_threshold_minutes' => '55'],
        ],
    ];

    $tracking = fleetopsJsonPayload($controller->getTrackingSettings());
    expect($tracking['provider'])->toBe('google_routes')
        ->and($tracking['alerts']['prolonged_stoppages']['duration_threshold_minutes'])->toBe(55)
        ->and($tracking['providers'])->toBe([
            [
                'key'          => 'osrm',
                'name'         => 'OSRM',
                'value'        => 'osrm',
                'label'        => 'OSRM',
                'capabilities' => ['routes'],
            ],
            [
                'key'          => 'google_routes',
                'name'         => 'Google Routes',
                'value'        => 'google_routes',
                'label'        => 'Google Routes',
                'capabilities' => ['traffic', 'eta'],
            ],
        ]);

    $adminSaved = fleetopsJsonPayload($controller->saveAdminTrackingSettings(new Request([
        'provider'  => 'calculated',
        'fallbacks' => 'osrm',
    ])));

    expect($controller->configured['fleet-ops.tracking-settings']['provider'])->toBe('calculated')
        ->and($controller->configured['fleet-ops.tracking-settings']['fallbacks'])->toBe(['osrm'])
        ->and($adminSaved['providers'])->toHaveCount(2)
        ->and(fleetopsJsonPayload($controller->getAdminTrackingSettings())['providers'])->toHaveCount(2);
});

test('setting controller handles map scheduling orchestrator and card field settings', function () {
    $controller                                     = new FleetOpsSettingControllerProbe();
    $controller->googleMapsApiKey                   = 'google-key';
    $controller->settings['fleet-ops.map-settings'] = [
        'mapProvider'     => 'google',
        'googleMapsMapId' => 'map-id',
    ];
    $controller->companySettings['fleet-ops.map-settings'] = [
        'mapProvider'                 => '',
        'googleMapsMapType'           => 'terrain',
        'showGoogleMapsTrafficLayer'  => 'true',
        'showGoogleMapsTransitLayer'  => 'false',
    ];
    $controller->companySettings['fleet-ops.scheduling-settings']      = ['horizon_days' => 14];
    $controller->companySettings['fleet-ops.allocation-settings']      = ['allocation_engine' => 'custom'];
    $controller->companySettings['fleet-ops.orchestrator-card-fields'] = ['standard' => ['status'], 'byConfig' => [], 'meta' => ['compact' => true]];

    $map              = fleetopsJsonPayload($controller->getMapSettings());
    $schedulingRead   = fleetopsJsonPayload($controller->getSchedulingSettings());
    $orchestratorRead = fleetopsJsonPayload($controller->getOrchestratorSettings());
    $cardFieldsRead   = fleetopsJsonPayload($controller->getOrchestratorCardFields());
    $savedMap         = fleetopsJsonPayload($controller->saveMapSettings(new Request([
        'settings' => [
            'mapProvider'                => 'invalid',
            'googleMapsMapType'          => 'hybrid',
            'showGoogleMapsTrafficLayer' => '1',
            'googleMapsApiKey'           => 'client-key',
        ],
    ])));
    $adminMap = fleetopsJsonPayload($controller->saveAdminMapSettings(new Request([
        'mapProvider'     => 'bad-provider',
        'googleMapsMapId' => 'admin-map',
    ])));
    $schedulingSave = fleetopsJsonPayload($controller->saveSchedulingSettings(new Request([
        'horizon_days'                   => '30',
        'default_shift_duration'         => '10',
        'hos_daily_limit'                => '12',
        'hos_weekly_limit'               => '60',
        'auto_activate_schedule'         => false,
        'notify_drivers_on_shift_change' => true,
    ])));
    $orchestratorSave = fleetopsJsonPayload($controller->saveOrchestratorSettings(new Request([
        'allocation_engine'           => 'vroom',
        'auto_allocate_on_create'     => true,
        'auto_reallocate_on_complete' => true,
        'max_travel_time_seconds'     => '1800',
        'balance_workload'            => true,
    ])));
    $cardFieldsSave = fleetopsJsonPayload($controller->saveOrchestratorCardFields(new Request([
        'settings' => ['standard' => ['tracking'], 'meta' => ['dense' => true]],
    ])));

    expect($map)->toMatchArray([
        'mapProvider'                => 'google',
        'googleMapsMapType'          => 'terrain',
        'showGoogleMapsTrafficLayer' => true,
        'showGoogleMapsTransitLayer' => false,
        'googleMapsApiKey'           => 'google-key',
        'googleMapsMapId'            => 'map-id',
    ])
        ->and(array_key_exists('googleMapsApiKey', $controller->configuredCompany['fleet-ops.map-settings']))->toBeFalse()
        ->and($controller->configuredCompany['fleet-ops.map-settings'])->toMatchArray([
            'mapProvider'       => 'leaflet',
            'googleMapsMapType' => 'hybrid',
        ])
        ->and($savedMap['googleMapsApiKey'])->toBe('google-key')
        ->and($controller->configured['fleet-ops.map-settings'])->toBe([
            'mapProvider'     => 'leaflet',
            'googleMapsMapId' => 'admin-map',
        ])
        ->and($adminMap)->toBe($controller->configured['fleet-ops.map-settings'])
        ->and(fleetopsJsonPayload($controller->getAdminMapSettings()))->toBe($controller->configured['fleet-ops.map-settings'])
        ->and($schedulingRead)->toBe(['horizon_days' => 14])
        ->and($schedulingSave)->toBe([
            'horizon_days'                   => 30,
            'default_shift_duration'         => 10,
            'hos_daily_limit'                => 12,
            'hos_weekly_limit'               => 60,
            'auto_activate_schedule'         => false,
            'notify_drivers_on_shift_change' => true,
        ])
        ->and($orchestratorRead)->toBe(['allocation_engine' => 'custom'])
        ->and($orchestratorSave)->toBe([
            'allocation_engine'           => 'vroom',
            'auto_allocate_on_create'     => true,
            'auto_reallocate_on_complete' => true,
            'max_travel_time_seconds'     => 1800,
            'balance_workload'            => true,
        ])
        ->and($cardFieldsRead)->toBe([
            'settings' => ['standard' => ['status'], 'byConfig' => [], 'meta' => ['compact' => true]],
        ])
        ->and($cardFieldsSave)->toMatchArray([
            'status'   => 'ok',
            'settings' => ['standard' => ['tracking'], 'byConfig' => [], 'meta' => ['dense' => true]],
        ]);
});

test('setting controller sanitizes leaflet tile provider urls', function () {
    $controller = new FleetOpsSettingControllerProbe();

    $saved = fleetopsJsonPayload($controller->saveMapSettings(new Request([
        'settings' => [
            'leafletTileUrl'     => '  https://tile.openstreetmap.org/{z}/{x}/{y}.png  ',
            'leafletDarkTileUrl' => 'javascript:alert(1)',
        ],
    ])));

    expect($saved['leafletTileUrl'])->toBe('https://tile.openstreetmap.org/{z}/{x}/{y}.png')
        ->and($saved['leafletDarkTileUrl'])->toBe('')
        ->and($controller->configuredCompany['fleet-ops.map-settings'])->toMatchArray([
            'leafletTileUrl'     => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'leafletDarkTileUrl' => '',
        ]);

    $savedNonString = fleetopsJsonPayload($controller->saveMapSettings(new Request([
        'settings' => [
            'leafletTileUrl'     => ['not' => 'a-string'],
            'leafletDarkTileUrl' => 'ftp://tiles.example.com/{z}/{x}/{y}.png',
        ],
    ])));

    expect($savedNonString['leafletTileUrl'])->toBe('')
        ->and($savedNonString['leafletDarkTileUrl'])->toBe('');

    $defaults = fleetopsJsonPayload((new FleetOpsSettingControllerProbe())->getMapSettings());

    expect($defaults['leafletTileUrl'])->toBe('')
        ->and($defaults['leafletDarkTileUrl'])->toBe('');
});
