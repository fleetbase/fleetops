<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Tracking\TrackingProviderRegistry;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Setting;
use Fleetbase\Support\Auth;
use Fleetbase\Support\NotificationRegistry;
use Illuminate\Http\Request;

/**
 * Class SettingController.
 */
class SettingController extends Controller
{
    /**
     * Save entity editing settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveEntityEditingSettings(Request $request)
    {
        $entityEditingSettings  = $request->input('entityEditingSettings', []);

        // Save entity editing settings
        Setting::configure('fleet-ops.entity-editing-settings', $entityEditingSettings);

        return response()->json(['entityEditingSettings' => $entityEditingSettings]);
    }

    /**
     * Retrieve entity editing settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEntityEditingSettings()
    {
        $entityEditingSettings  = Setting::where('key', 'fleet-ops.entity-editing-settings')->value('value');
        if (!$entityEditingSettings) {
            $entityEditingSettings = [];
        }

        return response()->json(['entityEditingSettings' => $entityEditingSettings]);
    }

    /**
     * Retrieve driver onboard settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDriverOnboardSettings($companyId)
    {
        $driverOnboardSettings  = Setting::where('key', 'fleet-ops.driver-onboard-settings.' . $companyId)->value('value');
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
        $driverOnboardSettings = $request->array('driverOnboardSettings', []);

        if ($driverOnboardSettings['enableDriverOnboardFromApp'] == false) {
            $driverOnboardSettings['driverMustProvideOnboardDoucments'] = false;
            $driverOnboardSettings['requiredOnboardDocuments']          = [];
            $driverOnboardSettings['driverOnboardAppMethod']            = '';
            $driverOnboardSettings['enableDriverOnboardFromApp']        = false;
        }

        Setting::configure('fleet-ops.driver-onboard-settings.' . $driverOnboardSettings['companyId'], $driverOnboardSettings);

        return response()->json(['driverOnboardSettings' => $driverOnboardSettings]);
    }

    public function saveCustomerEnabledOrderConfigs(Request $request)
    {
        $enabledOrderConfigs = array_values($request->array('enabledOrderConfigs'));
        Setting::configureCompany('fleet-ops.customer-enabled-order-configs', $enabledOrderConfigs);

        return response()->json($enabledOrderConfigs);
    }

    public function getCustomerEnabledOrderConfigs()
    {
        $enabledOrderConfigs = Setting::lookupFromCompany('fleet-ops.customer-enabled-order-configs', []);

        return response()->json(array_values($enabledOrderConfigs));
    }

    public function saveCustomerPortalPaymentConfig(Request $request)
    {
        $paymentsConfig = $request->array('paymentsConfig');
        Setting::configureCompany('fleet-ops.customer-payments-configs', $paymentsConfig);

        return response()->json($paymentsConfig);
    }

    public function getCustomerPortalPaymentConfig()
    {
        $paymentsConfig = Setting::lookupFromCompany('fleet-ops.customer-payments-configs', ['paymentsEnabled' => false]);

        if (is_array($paymentsConfig)) {
            // check if payments have been onboard
            $company                                    = Auth::getCompany();
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
        return response()->json(NotificationRegistry::getNotifiables());
    }

    /**
     * Get list of all valid notifiables.
     *
     * @return \Illuminate\Http\JsonResponse The JSON response
     */
    public function getNotificationRegistry()
    {
        return response()->json(NotificationRegistry::getNotificationsByPackage('fleet-ops'));
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
        $currentNotificationSettings = Setting::lookupCompany('notification_settings', []);
        Setting::configureCompany('notification_settings', array_merge($currentNotificationSettings, $notificationSettings));

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
        $notificationSettings = Setting::lookupCompany('notification_settings');

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
        Setting::configureCompany('routing', [
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
        $routingSettings = Setting::lookupCompany('routing', ['router' => 'osrm', 'unit' => 'km']);

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

        Setting::configureCompany('tracking', [
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
        $trackingSettings = Setting::lookupCompany('tracking', [
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

        Setting::configure('fleet-ops.tracking-settings', [
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
            'mapProvider' => 'leaflet',
        ];

        $systemMapSettings          = Setting::lookup('fleet-ops.map-settings', []);
        $mapSettings                = Setting::lookupFromCompany('fleet-ops.map-settings', $defaults);
        $mapSettings['mapProvider'] = data_get($mapSettings, 'mapProvider') ?: data_get($systemMapSettings, 'mapProvider', 'leaflet');

        // Source the Google Maps API key from the system-level services config
        // that is managed by the core-api admin settings panel. This ensures a
        // single source of truth and avoids duplicating key management.
        $mapSettings['googleMapsApiKey'] = config('services.google_maps.api_key', env('GOOGLE_MAPS_API_KEY', ''));
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
        $allowedProviders = ['leaflet', 'google'];
        if (isset($settings['mapProvider']) && !in_array($settings['mapProvider'], $allowedProviders)) {
            $settings['mapProvider'] = 'leaflet';
        }

        Setting::configureCompany('fleet-ops.map-settings', $settings);

        return response()->json($this->getMapSettings()->getData(true));
    }

    public function getAdminMapSettings()
    {
        $defaults = [
            'mapProvider'     => 'leaflet',
            'googleMapsMapId' => '',
        ];

        return response()->json(Setting::lookup('fleet-ops.map-settings', $defaults));
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

        Setting::configure('fleet-ops.map-settings', $settings);

        return response()->json($this->getAdminMapSettings()->getData(true));
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
        $settings = Setting::lookupFromCompany('fleet-ops.scheduling-settings', $defaults);

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
        Setting::configureCompany('fleet-ops.scheduling-settings', $settings);

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
        $settings = Setting::lookupFromCompany('fleet-ops.allocation-settings', $defaults);

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
        Setting::configureCompany('fleet-ops.allocation-settings', $settings);

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
        $settings = Setting::lookupFromCompany('fleet-ops.orchestrator-card-fields', $defaults);

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
        Setting::configureCompany('fleet-ops.orchestrator-card-fields', $normalized);

        return response()->json([
            'status'   => 'ok',
            'message'  => 'Orchestrator card fields saved.',
            'settings' => $normalized,
        ]);
    }

    protected function trackingDefaults(): array
    {
        $config         = config('fleetops.tracking', []);
        $systemSettings = Setting::lookup('fleet-ops.tracking-settings', []);

        $defaults = array_merge($config, is_array($systemSettings) ? $systemSettings : []);
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
                'enabled'                  => true,
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
        $registry = app(TrackingProviderRegistry::class);

        return collect($registry->all())->map(function ($provider, $key) {
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
}
