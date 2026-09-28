<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Jobs\DispatchTelematicsRetentionJobs;
use Fleetbase\FleetOps\Support\Telematics\Retention\RetentionPolicy;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Queue;
use Fleetbase\FleetOps\Tracking\TrackingProviderRegistry;
use Fleetbase\FleetOps\Traits\AuthorizesMethods;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Setting;
use Fleetbase\Support\Auth;
use Fleetbase\Support\NotificationRegistry;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Class SettingController.
 */
class SettingController extends Controller
{
    use AuthorizesMethods;

    public function __construct()
    {
        $this->authorizeMethods([
            'saveEntityEditingSettings'               => 'update navigator-settings',
            'savedDriverOnboardSettings'              => 'update navigator-settings',
            'saveCustomerEnabledOrderConfigs'         => 'update order-config',
            'saveCustomerPortalPaymentConfig'         => 'update payments',
            'saveNotificationSettings'                => 'update notification-settings',
            'saveRoutingSettings'                     => 'update routing-settings',
            'saveTrackingSettings'                    => 'update tracking-settings',
            'getAdminTrackingSettings'                => 'admin',
            'saveAdminTrackingSettings'               => 'admin',
            'saveMapSettings'                         => 'update map-settings',
            'getAdminMapSettings'                     => 'admin',
            'saveAdminMapSettings'                    => 'admin',
            'saveSchedulingSettings'                  => 'update scheduling-settings',
            'saveOrchestratorSettings'                => 'update routing-settings',
            'saveOrchestratorCardFields'              => 'update routing-settings',
            'getAdminTelematicsSettings'              => 'admin',
            'saveAdminTelematicsSettings'             => 'admin',
            'getTelematicsSettings'                   => 'view telematics-settings',
            'saveTelematicsSettings'                  => 'update telematics-settings',
            'getTelematicsStorageUsage'               => 'admin',
            'runTelematicsRetention'                  => 'admin',
        ]);
    }

    /**
     * Save entity editing settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveEntityEditingSettings(Request $request)
    {
        // The setting is one platform-wide map keyed by order config id. Only this
        // company's order configs may be written, and other companies' entries are kept.
        $ownKeys  = $this->companyOrderConfigKeys();
        $incoming = array_intersect_key((array) $request->input('entityEditingSettings', []), array_flip($ownKeys));
        $existing = (array) ($this->settingValue('fleet-ops.entity-editing-settings') ?? []);
        $merged   = array_merge(array_diff_key($existing, array_flip($ownKeys)), $incoming);

        $this->configureSetting('fleet-ops.entity-editing-settings', $merged);

        return response()->json(['entityEditingSettings' => $incoming]);
    }

    /**
     * Retrieve entity editing settings for this company's order configs.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEntityEditingSettings()
    {
        $entityEditingSettings = (array) ($this->settingValue('fleet-ops.entity-editing-settings') ?? []);
        $entityEditingSettings = array_intersect_key($entityEditingSettings, array_flip($this->companyOrderConfigKeys()));

        return response()->json(['entityEditingSettings' => $entityEditingSettings]);
    }

    /**
     * Ids (uuid and public id) of the session company's order configs.
     */
    protected function companyOrderConfigKeys(): array
    {
        return \Fleetbase\FleetOps\Models\OrderConfig::where('company_uuid', session('company'))
            ->get(['uuid', 'public_id'])
            ->flatMap(fn ($config) => array_filter([$config->uuid, $config->public_id]))
            ->values()
            ->all();
    }

    /**
     * Retrieve driver onboard settings for the session company.
     *
     * The route still carries a company id for backwards compatibility, but only
     * the session company's settings are ever returned.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDriverOnboardSettings($companyId = null)
    {
        $driverOnboardSettings  = $this->settingValue('fleet-ops.driver-onboard-settings.' . session('company'));
        if (!$driverOnboardSettings) {
            $driverOnboardSettings = [];
        }

        return response()->json(['driverOnboardSettings' => $driverOnboardSettings]);
    }

    /**
     * Save driver onboard settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function savedDriverOnboardSettings(Request $request)
    {
        $driverOnboardSettings              = $request->array('driverOnboardSettings', []);
        $driverOnboardSettings['companyId'] = session('company');

        if (empty($driverOnboardSettings['enableDriverOnboardFromApp'])) {
            $driverOnboardSettings['driverMustProvideOnboardDoucments'] = false;
            $driverOnboardSettings['requiredOnboardDocuments']          = [];
            $driverOnboardSettings['driverOnboardAppMethod']            = '';
            $driverOnboardSettings['enableDriverOnboardFromApp']        = false;
        }

        $this->configureSetting('fleet-ops.driver-onboard-settings.' . $driverOnboardSettings['companyId'], $driverOnboardSettings);

        return response()->json(['driverOnboardSettings' => $driverOnboardSettings]);
    }

    public function saveCustomerEnabledOrderConfigs(Request $request)
    {
        $enabledOrderConfigs = array_values($request->array('enabledOrderConfigs'));
        $this->configureCompanySetting('fleet-ops.customer-enabled-order-configs', $enabledOrderConfigs);

        return response()->json($enabledOrderConfigs);
    }

    public function getCustomerEnabledOrderConfigs()
    {
        $enabledOrderConfigs = $this->lookupFromCompanySetting('fleet-ops.customer-enabled-order-configs', []);

        return response()->json(array_values($enabledOrderConfigs));
    }

    public function saveCustomerPortalPaymentConfig(Request $request)
    {
        $paymentsConfig = $request->array('paymentsConfig');
        $this->configureCompanySetting('fleet-ops.customer-payments-configs', $paymentsConfig);

        return response()->json($paymentsConfig);
    }

    public function getCustomerPortalPaymentConfig()
    {
        $paymentsConfig = $this->lookupFromCompanySetting('fleet-ops.customer-payments-configs', ['paymentsEnabled' => false]);

        if (is_array($paymentsConfig)) {
            // check if payments have been onboard
            $company                                    = $this->currentCompany();
            $paymentsConfig['paymentsOnboardCompleted'] = $company && isset($company->stripe_connect_id);
        }

        return response()->json($paymentsConfig);
    }

    /**
     * Get list of all valid notifiables.
     *
     * @return \Illuminate\Http\JsonResponse The JSON response
     */
    public function getNotifiables()
    {
        return response()->json($this->notificationNotifiables());
    }

    /**
     * Get list of all valid notifiables.
     *
     * @return \Illuminate\Http\JsonResponse The JSON response
     */
    public function getNotificationRegistry()
    {
        return response()->json($this->notificationsByPackage('fleet-ops'));
    }

    /**
     * Save user notification settings.
     *
     * @param Request $request the HTTP request object containing the notification settings data
     *
     * @return \Illuminate\Http\JsonResponse a JSON response
     *
     * @throws \Exception if the provided notification settings data is not an array
     */
    public function saveNotificationSettings(Request $request)
    {
        $notificationSettings = $request->input('notificationSettings');
        if (!is_array($notificationSettings)) {
            throw new \Exception('Invalid notification settings data.');
        }
        $currentNotificationSettings = $this->lookupCompanySetting('notification_settings', []);
        $this->configureCompanySetting('notification_settings', array_merge($currentNotificationSettings, $notificationSettings));

        return response()->json([
            'status'  => 'ok',
            'message' => 'Notification settings succesfully saved.',
        ]);
    }

    /**
     * Retrieve and return the notification settings for the user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNotificationSettings()
    {
        $notificationSettings = $this->lookupCompanySetting('notification_settings');

        return response()->json([
            'status'               => 'ok',
            'message'              => 'Notification settings successfully fetched.',
            'notificationSettings' => $notificationSettings,
        ]);
    }

    /**
     * Save routing settings.
     *
     * @param Request $request the HTTP request object containing the routing settings data
     *
     * @return \Illuminate\Http\JsonResponse a JSON response
     */
    public function saveRoutingSettings(Request $request)
    {
        $displayEngine      = $request->input('display_engine', $request->input('router', 'osrm'));
        $optimizationEngine = $request->input('optimization_engine', $displayEngine);
        $unit               = $request->input('unit', 'km');
        $this->configureCompanySetting('routing', [
            'router'                      => $displayEngine,
            'display_engine'              => $displayEngine,
            'optimization_engine'         => $optimizationEngine,
            'routing_display_engine'      => $displayEngine,
            'routing_optimization_engine' => $optimizationEngine,
            'unit'                        => $unit,
        ]);

        return response()->json([
            'status'  => 'ok',
            'message' => 'Routing settings succesfully saved.',
        ]);
    }

    /**
     * Retrieve and return the routing settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRoutingSettings()
    {
        $routingSettings = $this->lookupCompanySetting('routing', ['router' => 'osrm', 'unit' => 'km']);

        $displayEngine                                  = data_get($routingSettings, 'display_engine', data_get($routingSettings, 'routing_display_engine', data_get($routingSettings, 'router', 'osrm')));
        $optimizationEngine                             = data_get($routingSettings, 'optimization_engine', data_get($routingSettings, 'routing_optimization_engine', $displayEngine));
        $routingSettings['router']                      = $displayEngine;
        $routingSettings['display_engine']              = $displayEngine;
        $routingSettings['optimization_engine']         = $optimizationEngine;
        $routingSettings['routing_display_engine']      = $displayEngine;
        $routingSettings['routing_optimization_engine'] = $optimizationEngine;

        // always default to km if no unit is set
        if (!isset($routingSettings['unit'])) {
            $routingSettings['unit'] = 'km';
        }

        return response()->json($routingSettings);
    }

    /**
     * Save order tracking intelligence settings.
     *
     * @param Request $request the HTTP request object containing tracking settings
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveTrackingSettings(Request $request)
    {
        $config    = $this->trackingDefaults();
        $fallbacks = $request->input('fallbacks', data_get($config, 'fallbacks', ['osrm', 'calculated']));
        if (is_string($fallbacks)) {
            $fallbacks = array_values(array_filter(array_map('trim', explode(',', $fallbacks))));
        }

        $this->configureCompanySetting('tracking', [
            'provider'                         => $request->input('provider', data_get($config, 'provider', 'google_routes')),
            'fallbacks'                        => $fallbacks,
            'traffic_enabled'                  => $request->boolean('traffic_enabled', data_get($config, 'traffic_enabled', true)),
            'cache_ttl_seconds'                => (int) $request->input('cache_ttl_seconds', data_get($config, 'cache_ttl_seconds', 60)),
            'route_cache_ttl_seconds'          => (int) $request->input('route_cache_ttl_seconds', data_get($config, 'route_cache_ttl_seconds', 600)),
            'stale_location_threshold_seconds' => (int) $request->input('stale_location_threshold_seconds', data_get($config, 'stale_location_threshold_seconds', 300)),
            'default_vehicle_speed_kph'        => (float) $request->input('default_vehicle_speed_kph', data_get($config, 'default_vehicle_speed_kph', 35)),
            'alerts'                           => $this->normalizeTrackingAlertSettings((array) $request->input('alerts', data_get($config, 'alerts', []))),
        ]);

        return response()->json([
            'status'  => 'ok',
            'message' => 'Tracking settings succesfully saved.',
        ]);
    }

    /**
     * Retrieve order tracking intelligence settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTrackingSettings()
    {
        $config           = $this->trackingDefaults();
        $trackingSettings = $this->lookupCompanySetting('tracking', [
            'provider'                         => data_get($config, 'provider', 'google_routes'),
            'fallbacks'                        => data_get($config, 'fallbacks', ['osrm', 'calculated']),
            'traffic_enabled'                  => data_get($config, 'traffic_enabled', true),
            'cache_ttl_seconds'                => data_get($config, 'cache_ttl_seconds', 60),
            'route_cache_ttl_seconds'          => data_get($config, 'route_cache_ttl_seconds', 600),
            'stale_location_threshold_seconds' => data_get($config, 'stale_location_threshold_seconds', 300),
            'default_vehicle_speed_kph'        => data_get($config, 'default_vehicle_speed_kph', 35),
            'alerts'                           => data_get($config, 'alerts', $this->trackingAlertDefaults()),
        ]);
        $trackingSettings['alerts']    = $this->normalizeTrackingAlertSettings(data_get($trackingSettings, 'alerts', []));
        $trackingSettings['providers'] = $this->trackingProviderOptions();

        return response()->json($trackingSettings);
    }

    public function getAdminTrackingSettings()
    {
        return response()->json(array_merge($this->trackingDefaults(), [
            'providers' => $this->trackingProviderOptions(),
        ]));
    }

    public function saveAdminTrackingSettings(Request $request)
    {
        $config    = config('fleetops.tracking', []);
        $fallbacks = $request->input('fallbacks', data_get($config, 'fallbacks', ['osrm', 'calculated']));
        if (is_string($fallbacks)) {
            $fallbacks = array_values(array_filter(array_map('trim', explode(',', $fallbacks))));
        }

        $this->configureSetting('fleet-ops.tracking-settings', [
            'provider'                         => $request->input('provider', data_get($config, 'provider', 'google_routes')),
            'fallbacks'                        => $fallbacks,
            'traffic_enabled'                  => $request->boolean('traffic_enabled', data_get($config, 'traffic_enabled', true)),
            'cache_ttl_seconds'                => (int) $request->input('cache_ttl_seconds', data_get($config, 'cache_ttl_seconds', 60)),
            'route_cache_ttl_seconds'          => (int) $request->input('route_cache_ttl_seconds', data_get($config, 'route_cache_ttl_seconds', 600)),
            'stale_location_threshold_seconds' => (int) $request->input('stale_location_threshold_seconds', data_get($config, 'stale_location_threshold_seconds', 300)),
            'default_vehicle_speed_kph'        => (float) $request->input('default_vehicle_speed_kph', data_get($config, 'default_vehicle_speed_kph', 35)),
            'alerts'                           => $this->normalizeTrackingAlertSettings((array) $request->input('alerts', data_get($config, 'alerts', []))),
        ]);

        return response()->json($this->getAdminTrackingSettings()->getData(true));
    }

    /**
     * Retrieve and return the map provider settings for the current company.
     *
     * The Google Maps API key is sourced exclusively from the system-level
     * services configuration managed by the core-api admin settings panel
     * (services.google_maps.api_key). FleetOps never stores or manages the
     * key independently — it simply reads it from the shared system config
     * and passes it to the frontend so the Google Maps adapter can initialise.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMapSettings()
    {
        $defaults = [
            'mapProvider'                 => 'leaflet',
            'leafletTileUrl'              => '',
            'leafletDarkTileUrl'          => '',
            'googleMapsMapType'           => 'roadmap',
            'showGoogleMapsTrafficLayer'  => false,
            'showGoogleMapsTransitLayer'  => false,
        ];

        $systemMapSettings          = $this->lookupSetting('fleet-ops.map-settings', []);
        $mapSettings                = $this->lookupFromCompanySetting('fleet-ops.map-settings', $defaults);
        $mapSettings['mapProvider'] = data_get($mapSettings, 'mapProvider') ?: data_get($systemMapSettings, 'mapProvider', 'leaflet');
        $mapSettings                = $this->normalizeCompanyMapSettings($mapSettings);

        // Source the Google Maps API key from the system-level services config
        // that is managed by the core-api admin settings panel. This ensures a
        // single source of truth and avoids duplicating key management.
        $mapSettings['googleMapsApiKey'] = $this->googleMapsApiKey();
        $mapSettings['googleMapsMapId']  = data_get($systemMapSettings, 'googleMapsMapId', '');

        return response()->json($mapSettings);
    }

    /**
     * Persist the map provider settings for the current company.
     *
     * The Google Maps API key is managed entirely through the core-api admin
     * settings panel (Settings → Services → Google Maps) and is therefore
     * never accepted or stored by this endpoint. Only the provider selection
     * and display preferences are persisted here.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveMapSettings(Request $request)
    {
        $settings = $request->input('settings', []);

        // The API key is managed at the system level via core-api — strip it
        // from the payload in case a client accidentally sends it.
        unset($settings['googleMapsApiKey']);

        // Validate provider value
        $settings = $this->normalizeCompanyMapSettings($settings);

        $this->configureCompanySetting('fleet-ops.map-settings', $settings);

        return response()->json($this->getMapSettings()->getData(true));
    }

    public function getAdminMapSettings()
    {
        $defaults = [
            'mapProvider'     => 'leaflet',
            'googleMapsMapId' => '',
        ];

        return response()->json($this->lookupSetting('fleet-ops.map-settings', $defaults));
    }

    public function saveAdminMapSettings(Request $request)
    {
        $allowedProviders = ['leaflet', 'google'];
        $mapProvider      = $request->input('mapProvider', 'leaflet');
        if (!in_array($mapProvider, $allowedProviders)) {
            $mapProvider = 'leaflet';
        }

        $settings = [
            'mapProvider'     => $mapProvider,
            'googleMapsMapId' => (string) $request->input('googleMapsMapId', ''),
        ];

        $this->configureSetting('fleet-ops.map-settings', $settings);

        return response()->json($this->getAdminMapSettings()->getData(true));
    }

    protected function normalizeCompanyMapSettings(array $settings): array
    {
        $allowedProviders = ['leaflet', 'google'];
        $mapProvider      = data_get($settings, 'mapProvider', 'leaflet');
        if (!in_array($mapProvider, $allowedProviders)) {
            $mapProvider = 'leaflet';
        }

        $allowedGoogleMapTypes = ['roadmap', 'satellite', 'hybrid', 'terrain'];
        $googleMapsMapType     = data_get($settings, 'googleMapsMapType', 'roadmap');
        if (!in_array($googleMapsMapType, $allowedGoogleMapTypes)) {
            $googleMapsMapType = 'roadmap';
        }

        return [
            ...$settings,
            'mapProvider'                => $mapProvider,
            'leafletTileUrl'             => $this->sanitizeTileUrl(data_get($settings, 'leafletTileUrl', '')),
            'leafletDarkTileUrl'         => $this->sanitizeTileUrl(data_get($settings, 'leafletDarkTileUrl', '')),
            'googleMapsMapType'          => $googleMapsMapType,
            'showGoogleMapsTrafficLayer' => filter_var(data_get($settings, 'showGoogleMapsTrafficLayer', false), FILTER_VALIDATE_BOOLEAN),
            'showGoogleMapsTransitLayer' => filter_var(data_get($settings, 'showGoogleMapsTransitLayer', false), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Sanitize a Leaflet XYZ tile URL template — only http(s) URLs are accepted,
     * anything else is discarded so the frontend falls back to the default tiles.
     */
    protected function sanitizeTileUrl($url): string
    {
        if (!is_string($url)) {
            return '';
        }

        $url = trim($url);
        if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
            return '';
        }

        return $url;
    }

    /**
     * Retrieve driver scheduling settings for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSchedulingSettings()
    {
        $defaults = [
            'horizon_days'                   => 60,
            'default_shift_duration'         => 8,
            'hos_daily_limit'                => 11,
            'hos_weekly_limit'               => 70,
            'auto_activate_schedule'         => true,
            'notify_drivers_on_shift_change' => false,
        ];
        $settings = $this->lookupFromCompanySetting('fleet-ops.scheduling-settings', $defaults);

        return response()->json($settings);
    }

    /**
     * Save driver scheduling settings for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveSchedulingSettings(Request $request)
    {
        $settings = [
            'horizon_days'                   => (int) $request->input('horizon_days', 60),
            'default_shift_duration'         => (int) $request->input('default_shift_duration', 8),
            'hos_daily_limit'                => (int) $request->input('hos_daily_limit', 11),
            'hos_weekly_limit'               => (int) $request->input('hos_weekly_limit', 70),
            'auto_activate_schedule'         => (bool) $request->input('auto_activate_schedule', true),
            'notify_drivers_on_shift_change' => (bool) $request->input('notify_drivers_on_shift_change', false),
        ];
        $this->configureCompanySetting('fleet-ops.scheduling-settings', $settings);

        return response()->json($settings);
    }

    /**
     * Retrieve order allocation settings for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrchestratorSettings()
    {
        $defaults = [
            'allocation_engine'           => 'vroom',
            'auto_allocate_on_create'     => false,
            'auto_reallocate_on_complete' => false,
            'max_travel_time_seconds'     => 3600,
            'balance_workload'            => false,
        ];
        $settings = $this->lookupFromCompanySetting('fleet-ops.allocation-settings', $defaults);

        return response()->json($settings);
    }

    /**
     * Save order allocation settings for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveOrchestratorSettings(Request $request)
    {
        $settings = [
            'allocation_engine'           => $request->input('allocation_engine', 'vroom'),
            'auto_allocate_on_create'     => (bool) $request->input('auto_allocate_on_create', false),
            'auto_reallocate_on_complete' => (bool) $request->input('auto_reallocate_on_complete', false),
            'max_travel_time_seconds'     => (int) $request->input('max_travel_time_seconds', 3600),
            'balance_workload'            => (bool) $request->input('balance_workload', false),
        ];
        $this->configureCompanySetting('fleet-ops.allocation-settings', $settings);

        return response()->json($settings);
    }

    /**
     * Retrieve orchestrator order card field settings for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrchestratorCardFields()
    {
        $defaults = [
            'standard'  => ['tracking', 'status', 'scheduled_at', 'customer', 'dropoff'],
            'byConfig'  => (object) [],
            'meta'      => [],
        ];
        $settings = $this->lookupFromCompanySetting('fleet-ops.orchestrator-card-fields', $defaults);

        return response()->json(['settings' => $settings]);
    }

    /**
     * Save orchestrator order card field settings for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveOrchestratorCardFields(Request $request)
    {
        $settings = $request->input('settings', []);

        $normalized = [
            'standard'  => $settings['standard'] ?? ['tracking', 'status', 'scheduled_at', 'customer', 'dropoff'],
            'byConfig'  => $settings['byConfig'] ?? [],
            'meta'      => $settings['meta'] ?? [],
        ];
        $this->configureCompanySetting('fleet-ops.orchestrator-card-fields', $normalized);

        return response()->json([
            'status'   => 'ok',
            'message'  => 'Orchestrator card fields saved.',
            'settings' => $normalized,
        ]);
    }

    protected function trackingDefaults(): array
    {
        $config         = config('fleetops.tracking', []);
        $systemSettings = $this->lookupSetting('fleet-ops.tracking-settings', []);

        $defaults           = array_merge($config, is_array($systemSettings) ? $systemSettings : []);
        $defaults['alerts'] = $this->normalizeTrackingAlertSettings(data_get($defaults, 'alerts', []));

        return $defaults;
    }

    protected function trackingAlertDefaults(): array
    {
        return [
            'late_departures' => [
                'enabled'              => true,
                'grace_period_minutes' => 15,
            ],
            'route_deviations' => [
                'enabled'                    => true,
                'distance_threshold_meters'  => 500,
            ],
            'prolonged_stoppages' => [
                'enabled'                    => true,
                'duration_threshold_minutes' => 30,
            ],
        ];
    }

    protected function normalizeTrackingAlertSettings(array $alerts): array
    {
        $defaults = $this->trackingAlertDefaults();

        return [
            'late_departures' => [
                'enabled'              => (bool) data_get($alerts, 'late_departures.enabled', data_get($defaults, 'late_departures.enabled')),
                'grace_period_minutes' => max(0, (int) data_get($alerts, 'late_departures.grace_period_minutes', data_get($defaults, 'late_departures.grace_period_minutes'))),
            ],
            'route_deviations' => [
                'enabled'                   => (bool) data_get($alerts, 'route_deviations.enabled', data_get($defaults, 'route_deviations.enabled')),
                'distance_threshold_meters' => max(0, (int) data_get($alerts, 'route_deviations.distance_threshold_meters', data_get($defaults, 'route_deviations.distance_threshold_meters'))),
            ],
            'prolonged_stoppages' => [
                'enabled'                    => (bool) data_get($alerts, 'prolonged_stoppages.enabled', data_get($defaults, 'prolonged_stoppages.enabled')),
                'duration_threshold_minutes' => max(0, (int) data_get($alerts, 'prolonged_stoppages.duration_threshold_minutes', data_get($defaults, 'prolonged_stoppages.duration_threshold_minutes'))),
            ],
        ];
    }

    protected function trackingProviderOptions(): array
    {
        return collect($this->trackingProviders())->map(function ($provider, $key) {
            $label = $key === 'osrm' ? 'OSRM' : str($key)->replace('_', ' ')->title()->toString();

            return [
                'key'          => $key,
                'name'         => $label,
                'value'        => $key,
                'label'        => $label,
                'capabilities' => $provider->capabilities()->toArray(),
            ];
        })->values()->all();
    }

    /**
     * Retrieve customer history preferences for the current company.
     *
     * Company values fall back to the system defaults (admin setting over
     * package config); the response carries both so the UI can show them.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTelematicsSettings()
    {
        if (!$this->currentCompany()) {
            return response()->error('No company session.', 401);
        }

        return response()->json($this->telematicsCompanySettings((array) $this->lookupFromCompanySetting(RetentionPolicy::SETTING_KEY, [])));
    }

    /**
     * Save customer history preferences within the administrator's policy.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveTelematicsSettings(Request $request)
    {
        $company = $this->currentCompany();
        if (!$company) {
            return response()->error('No company session.', 401);
        }

        $request->validate([
            'event_retention_days'    => ['nullable', 'integer', 'min:0', 'max:3650'],
            'position_retention_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        $saved = $this->withTelematicsPolicyLock(function () use ($request) {
            $preferences = RetentionPolicy::constrainCompanyPreferences($request->only(RetentionPolicy::HISTORY_KEYS), $this->telematicsDefaults());

            return $this->configureCompanySetting(RetentionPolicy::SETTING_KEY, $preferences);
        });
        if ($saved === false) {
            return response()->error('Unable to save telematics history preferences.', 500);
        }
        RetentionPolicy::flush($company->uuid);

        return response()->json(array_merge($this->getTelematicsSettings()->getData(true), [
            'status'  => 'ok',
            'message' => 'Telematics history preferences successfully saved.',
        ]));
    }

    /**
     * Retrieve the system-wide telematics retention defaults.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAdminTelematicsSettings()
    {
        return response()->json(array_merge($this->telematicsDefaults(), [
            'limits' => RetentionPolicy::LIMITS,
        ]));
    }

    /**
     * Save the system-wide telematics retention defaults.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveAdminTelematicsSettings(Request $request)
    {
        $request->validate([
            'max_event_retention_days'    => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'max_position_retention_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
        ]);

        try {
            $settings = $this->withTelematicsPolicyLock(function () use ($request) {
                $settings = RetentionPolicy::normalize($request->only(RetentionPolicy::keys()), $this->telematicsDefaults());
                $this->configureSetting(RetentionPolicy::SETTING_KEY, $settings);

                return $settings;
            });
            $this->reconcileTelematicsCompanyPreferences($settings);
        } finally {
            RetentionPolicy::flush();
        }

        return response()->json($this->getAdminTelematicsSettings()->getData(true));
    }

    /**
     * Report system-wide telematics storage, including data without an owner.
     *
     * Use table statistics for MySQL/MariaDB instead of scanning every tenant's
     * history. Oldest-row lookups and fallback counts have a database deadline.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTelematicsStorageUsage()
    {
        $ageColumns = [
            'device_events'        => 'created_at',
            'positions'            => 'created_at',
            'telematic_deliveries' => 'received_at',
            'telematic_sync_runs'  => 'created_at',
        ];
        $statistics = $this->storageTableStatistics(array_keys($ageColumns));
        $allRows    = fn ($query) => $query;
        $tables     = [];
        foreach ($ageColumns as $table => $ageColumn) {
            $tables[$table] = isset($statistics[$table])
                ? array_merge($statistics[$table], ['oldest' => $this->tableOldest($table, $allRows, $ageColumn)])
                : $this->tableUsage($table, $allRows, $ageColumn);
        }

        // Payload details require reading JSON-bearing rows, so they are opt-in
        // and omitted if they cannot be counted within the database deadline.
        if (request()->boolean('include_payload_counts', false)) {
            try {
                $rawPayloadRows  = $this->tableCount('device_events', fn ($query) => $query->whereNotNull('payload'));
                $compactAfter    = (int) $this->telematicsDefaults()['event_compact_after_days'];
                $compactableRows = $compactAfter > 0
                    ? $this->tableCount('device_events', fn ($query) => $query->whereNotNull('payload')->where('created_at', '<', now()->subDays($compactAfter)->toDateTimeString()))
                    : 0;

                $tables['device_events']['raw_payload_rows'] = $rawPayloadRows;
                $tables['device_events']['compactable_rows'] = $compactableRows;
            } catch (QueryException $exception) {
                if (!$this->isStorageUsageTimeout($exception)) {
                    throw $exception;
                }
            }
        }

        return response()->json([
            'tables'       => $tables,
            'scope'        => 'system',
            'generated_at' => now()->toISOString(),
        ]);
    }

    /**
     * Queue retention for all companies and orphaned data, independent of session company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function runTelematicsRetention()
    {
        $connection = (string) config('queue.default', 'sync');
        $driver     = config('queue.connections.' . $connection . '.driver', $connection);
        if (in_array($driver, ['sync', 'null'], true)) {
            return response()->error('System-wide telematics cleanup requires an asynchronous queue connection and a running queue worker.', 503);
        }

        $this->dispatchTelematicsPrune();

        return response()->json([
            'status'  => 'queued',
            'scope'   => 'system',
            'message' => 'System-wide telematics cleanup has been queued.',
        ], 202);
    }

    /**
     * System-wide retention defaults: package config overridden by the admin setting.
     */
    protected function telematicsDefaults(): array
    {
        $config = array_intersect_key((array) config('telematics.telemetry', []), RetentionPolicy::FALLBACKS);

        return RetentionPolicy::normalize((array) $this->lookupSetting(RetentionPolicy::SETTING_KEY, []), RetentionPolicy::normalize($config, RetentionPolicy::FALLBACKS));
    }

    /**
     * Customer responses expose history choices and constraints, never internal
     * ingestion, cleanup, storage or activity logging configuration.
     */
    protected function telematicsCompanySettings(array $stored, ?array $defaults = null): array
    {
        $defaults    = $defaults ?? $this->telematicsDefaults();
        $history     = array_flip(RetentionPolicy::HISTORY_KEYS);
        $preferences = RetentionPolicy::constrainCompanyPreferences($stored, $defaults);
        $effective   = RetentionPolicy::fromCompanyPreferences($preferences, $defaults)->toArray();
        $inherited   = RetentionPolicy::fromCompanyPreferences([], $defaults)->toArray();

        return array_merge(array_intersect_key($effective, $history), [
            'preferences' => array_merge(array_fill_keys(RetentionPolicy::HISTORY_KEYS, null), $preferences),
            'defaults'    => array_intersect_key($inherited, $history),
            'policy'      => array_intersect_key($defaults, array_flip(array_values(RetentionPolicy::MAXIMUMS))),
            'limits'      => array_intersect_key(RetentionPolicy::LIMITS, $history),
        ]);
    }

    /**
     * Serialize policy changes with a customer's policy lookup and preference
     * write, so a request cannot save an old unlimited choice after a new cap.
     * The empty row is only a lock anchor and keeps config fallbacks intact.
     */
    protected function withTelematicsPolicyLock(\Closure $callback): mixed
    {
        $setting = new Setting();
        if (!$setting->newQuery()->where('key', RetentionPolicy::SETTING_KEY)->exists()) {
            $setting->newQuery()->insertOrIgnore(['key' => RetentionPolicy::SETTING_KEY, 'value' => '[]']);
        }

        return $setting->getConnection()->transaction(function () use ($setting, $callback) {
            $setting->newQuery()->where('key', RetentionPolicy::SETTING_KEY)->lockForUpdate()->firstOrFail();

            return $callback();
        }, 3);
    }

    /**
     * A newly imposed maximum replaces existing unlimited or longer explicit
     * choices for every organization. Keep inherited preferences absent so they
     * continue following future system defaults. Process settings in bounded
     * batches rather than loading every organization or its telemetry history.
     */
    protected function reconcileTelematicsCompanyPreferences(array $defaults): void
    {
        $maximums = array_intersect_key($defaults, array_flip(array_values(RetentionPolicy::MAXIMUMS)));
        if (!array_filter($maximums, fn ($maximum) => $maximum > 0)) {
            return;
        }

        Setting::query()
            ->where('key', 'like', 'company.%.' . RetentionPolicy::SETTING_KEY)
            ->select('id')
            ->chunkById(200, function ($settings) {
                foreach ($settings as $setting) {
                    // Lock policy then company, as customer saves do. Reread
                    // both so a later admin change or customer preference is
                    // never overwritten using an earlier batch's snapshot.
                    $this->withTelematicsPolicyLock(function () use ($setting) {
                        $current = Setting::query()->whereKey($setting->getKey())->lockForUpdate()->first();
                        if (!$current) {
                            return;
                        }

                        $stored      = (array) $current->value;
                        $preferences = RetentionPolicy::companyPreferences($stored);
                        $constrained = RetentionPolicy::constrainCompanyPreferences($preferences, $this->telematicsDefaults());
                        if ($preferences === $constrained) {
                            return;
                        }

                        $current->value = array_replace($stored, $constrained);
                        $current->save();
                    });
                }
            });
    }

    protected function tableUsage(string $table, \Closure $scope, string $ageColumn): array
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [
                'rows'   => $this->tableCount($table, $scope),
                'oldest' => $this->tableOldest($table, $scope, $ageColumn),
            ];
        }

        $query = $scope(DB::table($table));
        $query->selectRaw('COUNT(*) AS row_count, MIN(' . $query->getGrammar()->wrap($ageColumn) . ') AS oldest');

        try {
            $usage = $this->storageUsageSelect($query->toSql(), $query->getBindings())[0];

            return [
                'rows'   => (int) $usage->row_count,
                'oldest' => $usage->oldest === null ? null : (string) $usage->oldest,
            ];
        } catch (QueryException $exception) {
            if (!$this->isStorageUsageTimeout($exception)) {
                throw $exception;
            }
        }

        // EXPLAIN does not execute the history scan. The caller's scope is
        // preserved when metadata is unavailable and a count reaches its deadline.
        $estimate = null;
        $query    = $scope(DB::table($table))->select($ageColumn);
        try {
            $plan = $this->storageUsageSelect($query->toSql(), $query->getBindings(), true)[0] ?? null;
            if (isset($plan->rows)) {
                $estimate = max(0, (int) round((float) $plan->rows * (float) ($plan->filtered ?? 100) / 100));
            }
        } catch (QueryException $exception) {
            if (!$this->isStorageUsageTimeout($exception)) {
                throw $exception;
            }
        }

        return ['rows' => $estimate, 'oldest' => null, 'rows_estimated' => true];
    }

    protected function tableCount(string $table, \Closure $scope): int
    {
        $query = $scope(DB::table($table))->selectRaw('COUNT(*) AS row_count');
        $usage = $this->storageUsageSelect($query->toSql(), $query->getBindings())[0];

        return (int) $usage->row_count;
    }

    protected function tableOldest(string $table, \Closure $scope, string $column): ?string
    {
        $query = $scope(DB::table($table));
        $query->selectRaw('MIN(' . $query->getGrammar()->wrap($column) . ') AS oldest');
        try {
            $oldest = $this->storageUsageSelect($query->toSql(), $query->getBindings())[0]->oldest ?? null;
        } catch (QueryException $exception) {
            if (!$this->isStorageUsageTimeout($exception)) {
                throw $exception;
            }

            return null;
        }

        return $oldest ? (string) $oldest : null;
    }

    /**
     * Bound each MySQL/MariaDB scan to one second without changing persistent
     * connection settings. A browser abort alone leaves database work running.
     */
    protected function storageUsageSelect(string $sql, array $bindings = [], bool $explain = false): array
    {
        $connection = DB::connection();
        $driver     = $connection->getDriverName();
        $isMariaDb  = $driver === 'mariadb';

        if ($connection instanceof MySqlConnection) {
            $isMariaDb = stripos((string) $connection->getReadPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), 'MariaDB') !== false;
        }

        if ($isMariaDb) {
            $sql = 'SET STATEMENT max_statement_time=1 FOR ' . ($explain ? 'EXPLAIN ' : '') . $sql;
        } else {
            if ($driver === 'mysql') {
                $sql = preg_replace('/^select\b/i', 'SELECT /*+ MAX_EXECUTION_TIME(1000) */', $sql, 1);
            }
            if ($explain) {
                $sql = 'EXPLAIN ' . $sql;
            }
        }

        return $connection->select($sql, $bindings);
    }

    protected function isStorageUsageTimeout(QueryException $exception): bool
    {
        return in_array((int) ($exception->errorInfo[1] ?? 0), [3024, 1969], true);
    }

    /**
     * Whole-table row estimates and allocated data/index bytes. Empty on other drivers.
     *
     * @return array<string, array<string, int|bool|null>>
     */
    protected function storageTableStatistics(array $tables): array
    {
        $connection = DB::connection();
        if (!in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }

        $prefix       = $connection->getTablePrefix();
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        try {
            $rows = $this->storageUsageSelect(
                'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
                array_map(fn ($table) => $prefix . $table, $tables)
            );
        } catch (QueryException $exception) {
            if (!$this->isStorageUsageTimeout($exception)) {
                throw $exception;
            }

            return [];
        }

        $statistics = [];
        foreach ($rows as $row) {
            $statistics[substr($row->name, strlen($prefix))] = [
                'rows'            => $row->row_count === null ? null : (int) $row->row_count,
                'rows_estimated'  => true,
                'estimated_bytes' => $row->data_bytes === null || $row->index_bytes === null ? null : (int) $row->data_bytes + (int) $row->index_bytes,
            ];
        }

        return $statistics;
    }

    protected function dispatchTelematicsPrune(): void
    {
        Queue::dispatch(new DispatchTelematicsRetentionJobs());
    }

    protected function configureSetting(string $key, mixed $value): mixed
    {
        return Setting::configure($key, $value);
    }

    protected function configureCompanySetting(string $key, mixed $value): mixed
    {
        return Setting::configureCompany($key, $value);
    }

    protected function settingValue(string $key): mixed
    {
        return Setting::where('key', $key)->value('value');
    }

    protected function lookupSetting(string $key, mixed $defaultValue = null): mixed
    {
        return Setting::lookup($key, $defaultValue);
    }

    protected function lookupFromCompanySetting(string $key, mixed $defaultValue = null): mixed
    {
        return Setting::lookupFromCompany($key, $defaultValue);
    }

    protected function lookupCompanySetting(string $key, mixed $defaultValue = null): mixed
    {
        return Setting::lookupCompany($key, $defaultValue);
    }

    protected function currentCompany(): mixed
    {
        return Auth::getCompany();
    }

    protected function notificationNotifiables(): array
    {
        return NotificationRegistry::getNotifiables();
    }

    protected function notificationsByPackage(string $package): array
    {
        return NotificationRegistry::getNotificationsByPackage($package);
    }

    protected function trackingProviders(): array
    {
        return app(TrackingProviderRegistry::class)->all();
    }

    protected function googleMapsApiKey(): string
    {
        return config('services.google_maps.api_key', env('GOOGLE_MAPS_API_KEY', ''));
    }
}
