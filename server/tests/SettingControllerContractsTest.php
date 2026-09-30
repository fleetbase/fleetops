<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SettingController;
use Illuminate\Http\Request;

class FleetOpsSettingControllerProbe extends SettingController
{
    public array $configured                   = [];
    public array $configuredCompany            = [];
    public array $settingValues                = [];
    public array $orderConfigKeys              = [];
    public array $settings                     = [];
    public array $companySettings              = [];
    public array $lookupCompanySettings        = [];
    public mixed $company                      = null;
    public array $notifiables                  = [];
    public array $notifications                = [];
    public array $providers                    = [];
    public string $googleMapsApiKey            = '';
    public array $reconciledTelematicsDefaults = [];

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

    protected function companyOrderConfigKeys(): array
    {
        return $this->orderConfigKeys;
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

    protected function reconcileTelematicsCompanyPreferences(array $defaults): void
    {
        $this->reconciledTelematicsDefaults = $defaults;
    }

    protected function withTelematicsPolicyLock(Closure $callback): mixed
    {
        return $callback();
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

    public array $counts          = [];
    public array $oldest          = [];
    public array $tableStatistics = [];
    public array $countScopes     = [];
    public array $pruned          = [];

    protected function tableUsage(string $table, Closure $scope, string $ageColumn): array
    {
        // System storage must not add company or connection filters.
        $scope(new stdClass());

        return [
            'rows'   => $this->tableCount($table, $scope),
            'oldest' => $this->tableOldest($table, $scope, $ageColumn),
        ];
    }

    protected function tableCount(string $table, Closure $scope): int
    {
        $this->countScopes[] = $table;

        return $this->counts[$table] ?? 0;
    }

    protected function tableOldest(string $table, Closure $scope, string $column): ?string
    {
        return $this->oldest[$table] ?? null;
    }

    protected function storageTableStatistics(array $tables): array
    {
        return $this->tableStatistics;
    }

    protected function dispatchTelematicsPrune(): void
    {
        $this->pruned[] = 'system';
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
    session(['company' => 'company-1']);
    $controller                  = new FleetOpsSettingControllerProbe();
    $controller->orderConfigKeys = ['orders'];

    // The setting is one platform-wide map keyed by order config: another company's entry must survive,
    // and this company cannot write keys for order configs it does not own.
    $controller->settingValues['fleet-ops.entity-editing-settings'] = ['foreign-config' => ['editable' => true]];
    $entityPayload                                                  = fleetopsJsonPayload($controller->saveEntityEditingSettings(new Request([
        'entityEditingSettings' => ['orders' => ['editable' => true], 'foreign-config' => ['editable' => false]],
    ])));
    $controller->settingValues['fleet-ops.entity-editing-settings'] = ['orders' => ['editable' => false], 'foreign-config' => ['editable' => true]];

    // A request-supplied company id is ignored in favour of the session company.
    $disabledDriverPayload = fleetopsJsonPayload($controller->savedDriverOnboardSettings(new Request([
        'driverOnboardSettings' => [
            'companyId'                         => 'company-2',
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
        ->and($controller->configured['fleet-ops.entity-editing-settings'])->toBe(['foreign-config' => ['editable' => true], 'orders' => ['editable' => true]])
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
        ->and($controller->configured)->not->toHaveKey('fleet-ops.driver-onboard-settings.company-2')
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

test('telematics settings expose only customer history preferences within administrator limits', function () {
    config(['telematics.telemetry' => ['event_retention_days' => 45, 'processed_retention_hours' => 48, 'log_telemetry_activity' => false, 'poll_queue' => 'default']]);
    $controller                                                    = new FleetOpsSettingControllerProbe();
    $controller->company                                           = (object) ['uuid' => 'company-1'];
    $controller->settings['fleet-ops.telematics-settings']         = ['position_retention_days' => 120, 'event_retention_days' => 99999, 'max_event_retention_days' => 60];
    $controller->companySettings['fleet-ops.telematics-settings']  = ['event_compact_after_days' => 3, 'log_telemetry_activity' => '1'];

    $settings = fleetopsJsonPayload($controller->getTelematicsSettings());
    expect($settings['event_retention_days'])->toBe(60)
        ->and($settings['position_retention_days'])->toBe(120)
        ->and($settings)->not->toHaveKeys(['event_compact_after_days', 'processed_retention_hours', 'log_telemetry_activity'])
        ->and($settings['preferences'])->toBe(['event_retention_days' => null, 'position_retention_days' => null])
        ->and($settings['defaults'])->toBe(['event_retention_days' => 60, 'position_retention_days' => 120])
        ->and($settings['policy'])->toBe(['max_event_retention_days' => 60, 'max_position_retention_days' => 0])
        ->and($settings['limits'])->toBe(['event_retention_days' => [1, 3650], 'position_retention_days' => [1, 3650]]);

    $saved = fleetopsJsonPayload($controller->saveTelematicsSettings(new Request([
        'event_retention_days'      => '0',
        'event_compact_after_days'  => -3,
        'processed_retention_hours' => 5000,
        'log_telemetry_activity'    => 'false',
        'unexpected'                => 'ignored',
    ])));
    expect($saved['status'])->toBe('ok')
        ->and($saved['event_retention_days'])->toBe(60)
        ->and($saved['position_retention_days'])->toBe(120)
        ->and($saved['preferences'])->toBe(['event_retention_days' => 60, 'position_retention_days' => null])
        ->and($saved)->not->toHaveKeys(['event_compact_after_days', 'processed_retention_hours', 'log_telemetry_activity'])
        ->and($controller->configuredCompany['fleet-ops.telematics-settings'])->toBe(['event_retention_days' => 60]);

    $admin = fleetopsJsonPayload($controller->getAdminTelematicsSettings());
    expect($admin['event_retention_days'])->toBe(3650)
        ->and($admin['limits'])->toHaveKeys(['sync_run_retention_days', 'max_event_retention_days', 'max_position_retention_days'])
        ->and($admin['log_telemetry_activity'])->toBeFalse();

    $savedAdmin = fleetopsJsonPayload($controller->saveAdminTelematicsSettings(new Request([
        'event_retention_days'        => 60,
        'sync_run_retention_days'     => 0,
        'max_event_retention_days'    => 90,
        'max_position_retention_days' => 45,
        'log_telemetry_activity'      => true,
    ])));
    expect($savedAdmin['event_retention_days'])->toBe(60)
        ->and($savedAdmin['sync_run_retention_days'])->toBe(0)
        ->and($savedAdmin['processed_retention_hours'])->toBe(48)
        ->and($savedAdmin['max_event_retention_days'])->toBe(90)
        ->and($savedAdmin['max_position_retention_days'])->toBe(45)
        ->and($savedAdmin['log_telemetry_activity'])->toBeTrue()
        ->and($controller->reconciledTelematicsDefaults['max_event_retention_days'])->toBe(90)
        ->and($controller->reconciledTelematicsDefaults['max_position_retention_days'])->toBe(45)
        ->and($controller->configured['fleet-ops.telematics-settings']['position_retention_days'])->toBe(120);

    $partialAdmin = fleetopsJsonPayload($controller->saveAdminTelematicsSettings(new Request(['log_telemetry_activity' => false])));
    expect($partialAdmin['max_event_retention_days'])->toBe(90)
        ->and($partialAdmin['max_position_retention_days'])->toBe(45);

    $inherited = fleetopsJsonPayload($controller->saveTelematicsSettings(new Request(['event_retention_days' => null, 'position_retention_days' => null])));
    expect($inherited['event_retention_days'])->toBe(60)
        ->and($inherited['position_retention_days'])->toBe(45)
        ->and($inherited['preferences'])->toBe(['event_retention_days' => null, 'position_retention_days' => null])
        ->and($controller->configuredCompany['fleet-ops.telematics-settings'])->toBe([]);
    config(['telematics.telemetry' => []]);
});

test('explicit unlimited customer history stays distinct from inheritance and reads back with current maximums', function () {
    $controller          = new FleetOpsSettingControllerProbe();
    $controller->company = (object) ['uuid' => 'company-1'];

    $saved = fleetopsJsonPayload($controller->saveTelematicsSettings(new Request([
        'event_retention_days'    => 0,
        'position_retention_days' => null,
    ])));
    expect($saved['event_retention_days'])->toBe(0)
        ->and($saved['preferences'])->toBe(['event_retention_days' => 0, 'position_retention_days' => null])
        ->and($controller->configuredCompany['fleet-ops.telematics-settings'])->toBe(['event_retention_days' => 0])
        ->and(fleetopsJsonPayload($controller->getTelematicsSettings())['preferences'])->toBe($saved['preferences']);

    $controller->settings['fleet-ops.telematics-settings'] = ['max_event_retention_days' => 90, 'max_position_retention_days' => 360];
    $loaded                                                = fleetopsJsonPayload($controller->getTelematicsSettings());
    expect($loaded['event_retention_days'])->toBe(90)
        ->and($loaded['preferences'])->toBe(['event_retention_days' => 90, 'position_retention_days' => null])
        ->and($loaded['policy'])->toBe(['max_event_retention_days' => 90, 'max_position_retention_days' => 360]);
});

test('telematics customer settings require a company session', function () {
    $controller = new FleetOpsSettingControllerProbe();
    expect($controller->getTelematicsSettings()->getStatusCode())->toBe(401)
        ->and($controller->saveTelematicsSettings(new Request())->getStatusCode())->toBe(401)
        ->and($controller->configuredCompany)->toBe([])
        ->and($controller->pruned)->toBe([]);
});

test('admin telematics storage and cleanup cover the system without a company session', function () {
    $hadRequest          = app()->bound('request');
    $originalRequest     = $hadRequest ? app('request') : null;
    $originalQueueConfig = config('queue', []);
    config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
    app()->instance('request', new Request(['include_payload_counts' => true]));
    Illuminate\Support\Carbon::setTestNow('2026-09-23 12:00:00 UTC');
    try {
        $controller                  = new FleetOpsSettingControllerProbe();
        $controller->counts          = ['device_events' => 500, 'positions' => 40, 'telematic_deliveries' => 6, 'telematic_sync_runs' => 3];
        $controller->oldest          = ['device_events' => '2026-08-01 00:00:00'];
        $controller->tableStatistics = [
            'device_events' => ['rows' => 500, 'rows_estimated' => true, 'estimated_bytes' => 16000000],
            'positions'     => ['rows' => 40, 'rows_estimated' => true, 'estimated_bytes' => 28000],
        ];

        $usage = fleetopsJsonPayload($controller->getTelematicsStorageUsage());
        expect($usage['tables']['device_events'])->toBe([
            'rows'             => 500,
            'rows_estimated'   => true,
            'estimated_bytes'  => 16000000,
            'oldest'           => '2026-08-01 00:00:00',
            'raw_payload_rows' => 500,
            'compactable_rows' => 500,
        ])
            ->and($usage['tables']['positions'])->toBe(['rows' => 40, 'rows_estimated' => true, 'estimated_bytes' => 28000, 'oldest' => null])
            ->and($usage['tables']['telematic_deliveries'])->toBe(['rows' => 6, 'oldest' => null])
            ->and($usage['tables']['telematic_sync_runs']['rows'])->toBe(3)
            ->and($usage['scope'])->toBe('system')
            ->and($usage)->not->toHaveKey('company')
            ->and($usage['generated_at'])->toBe('2026-09-23T12:00:00.000000Z')
            ->and($controller->countScopes)->toBe(['telematic_deliveries', 'telematic_sync_runs', 'device_events', 'device_events']);

        // Missing metadata uses bounded full-table counts, independent of any organization's connections.
        $controller->tableStatistics                           = [];
        $controller->settings['fleet-ops.telematics-settings'] = ['event_compact_after_days' => 0];
        $controller->countScopes                               = [];
        $usage                                                 = fleetopsJsonPayload($controller->getTelematicsStorageUsage());
        expect($usage['tables']['telematic_deliveries'])->toBe(['rows' => 6, 'oldest' => null])
            ->and($usage['tables']['telematic_sync_runs'])->toBe(['rows' => 3, 'oldest' => null])
            ->and($usage['tables']['device_events']['compactable_rows'])->toBe(0)
            ->and($controller->countScopes)->toBe(['device_events', 'positions', 'telematic_deliveries', 'telematic_sync_runs', 'device_events']);

        $queued = $controller->runTelematicsRetention();
        expect($queued->getStatusCode())->toBe(202)
            ->and(fleetopsJsonPayload($queued)['status'])->toBe('queued')
            ->and(fleetopsJsonPayload($queued)['scope'])->toBe('system')
            ->and($controller->pruned)->toBe(['system']);
    } finally {
        if ($hadRequest) {
            app()->instance('request', $originalRequest);
        } else {
            app()->forgetInstance('request');
        }
        config(['queue' => $originalQueueConfig]);
        Illuminate\Support\Carbon::setTestNow();
    }
});

test('system cleanup does not run an entire platform inline on a synchronous queue', function () {
    $originalQueueConfig = config('queue', []);
    try {
        config(['queue.default' => 'sync', 'queue.connections.sync.driver' => 'sync']);
        $controller = new FleetOpsSettingControllerProbe();
        expect($controller->runTelematicsRetention()->getStatusCode())->toBe(503)
            ->and($controller->pruned)->toBe([]);
    } finally {
        config(['queue' => $originalQueueConfig]);
    }
});

test('telematics storage helpers query all rows and allocated table bytes from MySQL statistics', function () {
    $connection = new class(new PDO('sqlite::memory:')) extends Illuminate\Database\SQLiteConnection {
        public string $driver = 'sqlite';
        public array $selects = [];

        public function getDriverName()
        {
            return $this->driver;
        }

        public function select($query, $bindings = [], $useReadPdo = true)
        {
            if (str_contains($query, 'information_schema')) {
                $this->selects[] = [$query, $bindings];

                // information_schema reports prefixed table names.
                return [(object) ['name' => $bindings[0], 'row_count' => '500', 'data_bytes' => '16000000', 'index_bytes' => '5000'], (object) ['name' => $bindings[1], 'row_count' => 40, 'data_bytes' => 28000, 'index_bytes' => 2000]];
            }

            return parent::select($query, $bindings, $useReadPdo);
        }
    };
    $connection->setTablePrefix('');
    $originalDb = app()->bound('db') ? app('db') : null;
    app()->instance('db', new class($connection) {
        public function __construct(public $connection)
        {
        }

        public function connection($name = null)
        {
            return $this->connection;
        }

        public function __call($method, $arguments)
        {
            return $this->connection->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    try {
        $schema = $connection->getSchemaBuilder();
        $schema->create('telematics', function ($table) {
            $table->increments('id');
            $table->string('uuid');
            $table->string('company_uuid');
        });
        $schema->create('device_events', function ($table) {
            $table->increments('id');
            $table->string('company_uuid')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $connection->table('telematics')->insert([['uuid' => 'tm-1', 'company_uuid' => 'company-1'], ['uuid' => 'tm-2', 'company_uuid' => 'company-2']]);
        $connection->table('device_events')->insert([
            ['company_uuid' => 'company-1', 'created_at' => '2026-08-01 00:00:00'],
            ['company_uuid' => 'company-1', 'created_at' => '2026-09-01 00:00:00'],
            ['company_uuid' => 'company-2', 'created_at' => '2026-07-01 00:00:00'],
        ]);

        $controller = new class extends SettingController {
            public function usage(string $table, Closure $scope, string $column): array
            {
                return $this->tableUsage($table, $scope, $column);
            }

            public function statistics(array $tables): array
            {
                return $this->storageTableStatistics($tables);
            }
        };
        expect($controller->usage('device_events', fn ($query) => $query, 'created_at'))->toBe(['rows' => 3, 'oldest' => '2026-07-01 00:00:00'])
            ->and($controller->usage('device_events', fn ($query) => $query->where('company_uuid', 'none'), 'created_at'))->toBe(['rows' => 0, 'oldest' => null])
            ->and($controller->statistics(['device_events', 'positions']))->toBe([]);

        $connection->driver = 'mysql';
        $connection->setTablePrefix('fb_');
        expect($controller->statistics(['device_events', 'positions']))->toBe([
            'device_events' => ['rows' => 500, 'rows_estimated' => true, 'estimated_bytes' => 16005000],
            'positions'     => ['rows' => 40, 'rows_estimated' => true, 'estimated_bytes' => 30000],
        ])
            ->and($connection->selects[0][1])->toBe(['fb_device_events', 'fb_positions'])
            ->and($connection->selects[0][0])->toContain('TABLE_NAME IN (?, ?)');
    } finally {
        if ($originalDb) {
            app()->instance('db', $originalDb);
        } else {
            app()->forgetInstance('db');
        }
        Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    }
});

test('admin telematics cleanup dispatches the system-wide fanout job', function () {
    $controller = new class extends SettingController {
        public function prune(): void
        {
            $this->dispatchTelematicsPrune();
        }
    };
    $originalCache       = Illuminate\Support\Facades\Cache::getFacadeRoot();
    $originalCacheConfig = config('cache', []);
    $dispatcherContract  = Illuminate\Contracts\Bus\Dispatcher::class;
    $originalDispatcher  = app()->bound($dispatcherContract) ? app($dispatcherContract) : null;
    try {
        config(['cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array']]);
        Illuminate\Support\Facades\Cache::swap(new Illuminate\Cache\CacheManager(app()));
        $dispatcher = new class(app()) extends Illuminate\Bus\Dispatcher {
            public array $jobs = [];
            public bool $fail  = true;

            public function dispatch($command)
            {
                if ($this->fail) {
                    throw new RuntimeException('Broker unavailable');
                }
                $this->jobs[] = $command;

                return $command;
            }
        };
        app()->instance($dispatcherContract, $dispatcher);

        expect(fn () => $controller->prune())->toThrow(RuntimeException::class, 'Broker unavailable');
        $dispatcher->fail = false;
        $controller->prune();
        $controller->prune();
        expect($dispatcher->jobs)->toHaveCount(1)
            ->and($dispatcher->jobs[0])->toBeInstanceOf(Fleetbase\FleetOps\Jobs\DispatchTelematicsRetentionJobs::class)
            ->and($dispatcher->jobs[0]->afterCompanyId)->toBe(0);
    } finally {
        Illuminate\Support\Facades\Cache::swap($originalCache);
        config(['cache' => $originalCacheConfig]);
        if ($originalDispatcher) {
            app()->instance($dispatcherContract, $originalDispatcher);
        } else {
            app()->forgetInstance($dispatcherContract);
        }
    }
});

test('customer history save reports a failed persistence operation', function () {
    $controller = new class extends FleetOpsSettingControllerProbe {
        protected function configureCompanySetting(string $key, mixed $value): mixed
        {
            return false;
        }
    };
    $controller->company = (object) ['uuid' => 'company-a'];
    $response            = $controller->saveTelematicsSettings(Request::create('/', 'POST', ['event_retention_days' => 0]));
    expect($response->getStatusCode())->toBe(500);
});

test('optional payload counts omit timeouts but surface other database errors', function () {
    $hadRequest = app()->bound('request');
    $original   = $hadRequest ? app('request') : null;
    app()->instance('request', Request::create('/', 'GET', ['include_payload_counts' => true]));
    try {
        $controller = new class extends FleetOpsSettingControllerProbe {
            public int $errorCode = 3024;

            protected function tableUsage(string $table, Closure $scope, string $ageColumn): array
            {
                return ['rows' => 5, 'oldest' => null];
            }

            protected function tableCount(string $table, Closure $scope): int
            {
                $error            = new PDOException('Query failed');
                $error->errorInfo = ['HY000', $this->errorCode, 'Query failed'];
                throw new Illuminate\Database\QueryException('mysql', 'select payload', [], $error);
            }
        };
        $data = $controller->getTelematicsStorageUsage()->getData(true);
        expect($data['scope'])->toBe('system')->and($data['tables']['device_events'])->toBe(['rows' => 5, 'oldest' => null]);
        $controller->errorCode = 1146;
        expect(fn () => $controller->getTelematicsStorageUsage())->toThrow(Illuminate\Database\QueryException::class);
    } finally {
        if ($hadRequest) {
            app()->instance('request', $original);
        } else {
            app()->forgetInstance('request');
        }
    }
});
