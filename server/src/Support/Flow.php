<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\Models\Company;
use Fleetbase\Models\Extension;
use Fleetbase\Models\ExtensionInstall;
use Fleetbase\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class Flow
{
    /**
     * Returns all the order type configurations for the current users session.
     */
    public static function queryOrderConfigurations($queryCallback = null): ?Collection
    {
        $installedExtensions = [];

        // get all installed order configs
        $installed = ExtensionInstall::where('company_uuid', session('company'))->whereHas('extension', function ($query) use ($queryCallback) {
            $query->where('meta_type', 'order_config');
            if (is_callable($queryCallback)) {
                $queryCallback($query);
            }
        })->with('extension')->get();

        // morph installed into extensions
        foreach ($installed as $install) {
            $installedExtensions[] = $install->asExtension();
        }

        // get authored extension installs
        $authoredQuery = Extension::where(['author_uuid' => session('company'), 'meta_type' => 'order_config', 'status' => 'private']);

        if (is_callable($queryCallback)) {
            $queryCallback($authoredQuery);
        }

        $authored = $authoredQuery->get();

        // create array of configs
        /** @var \Illuminate\Support\Collection $configs */
        $configs = collect([...$installedExtensions, ...$authored]);

        // if no installed configs always place default config
        if ($configs->isEmpty()) {
            $configs = $configs->merge(Flow::getAllDefaultOrderConfigs());
        }

        return $configs;
    }

    /**
     * Returns all the order type configurations for the current users session.
     */
    public static function getOrderConfigsForSession(): ?Collection
    {
        $installedExtensions = [];

        // get all installed order configs
        $installed = ExtensionInstall::where('company_uuid', session('company'))->whereHas('extension', function ($query) {
            $query->where('meta_type', 'order_config');
        })->with('extension')->get();

        // morph installed into extensions
        foreach ($installed as $install) {
            $installedExtensions[] = $install->asExtension();
        }

        // get authored extension installs
        $authored = Extension::where(['author_uuid' => session('company'), 'meta_type' => 'order_config', 'status' => 'private'])->get();

        // create array of configs
        /** @var \Illuminate\Support\Collection $configs */
        $configs = collect([...$installedExtensions, ...$authored]);

        // if no installed configs always place default config
        if ($configs->isEmpty()) {
            $configs = $configs->merge(Flow::getAllDefaultOrderConfigs());
        }

        return $configs;
    }

    /**
     * Returns all the order type configurations for the current users session.
     */
    public static function getOrderConfigs(Order $order): Collection
    {
        $installedExtensions = [];

        // get all installed order configs
        $installed = ExtensionInstall::where('company_uuid', $order->company_uuid)->whereHas('extension', function ($query) {
            $query->where('meta_type', 'order_config');
        })->with('extension')->get();

        // morph installed into extensions
        foreach ($installed as $install) {
            $installedExtensions[] = $install->asExtension();
        }

        // get authored extension installs
        $authored = Extension::where(['author_uuid' => $order->company_uuid, 'meta_type' => 'order_config', 'status' => 'private'])->get();

        // create array of configs
        /** @var \Illuminate\Support\Collection $configs */
        $configs = collect([...$installedExtensions, ...$authored]);

        // if no installed configs always place default config
        if ($configs->isEmpty()) {
            $configs = $configs->merge(Flow::getAllDefaultOrderConfigs());
        }

        return $configs;
    }

    /**
     * Returns a order type configuration by key.
     */
    public static function getOrderConfig(Order $order): ?Extension
    {
        if ($order->type === 'default' || empty($order->type)) {
            if (empty($order->type)) {
                $order->update(['type' => 'default']);
            }

            return static::getDefaultOrderConfig();
        }

        $configs = static::getOrderConfigs($order);

        return $configs->firstWhere('key', $order->type);
    }

    /**
     * Returns a order type configuration by key.
     *
     * @return array
     */
    public static function getOrderConfigFlow(Order $order)
    {
        if ($order->type === 'default' || empty($order->type)) {
            if (empty($order->type)) {
                $order->update(['type' => 'default']);
            }

            $config = static::getDefaultOrderConfig();

            if ($config) {
                return data_get($config, 'meta.flow');
            }

            return null;
        }

        $configs = static::getOrderConfigs($order);
        $config  = $configs->firstWhere('key', $order->type);

        if ($config) {
            return data_get($config, 'meta.flow');
        }

        return null;
    }

    /**
     * Returns a order type configuration by key.
     */
    public static function getOrderFlow(Order $order): ?array
    {
        $config = static::getOrderConfig($order);
        $vars   = static::getOrderFlowVars($order);
        $code   = strtolower($order->status);
        $status = data_get($config, 'meta.flow.' . $code . '.events');

        $flow = static::bindVariablesToFlow($status, $vars);
        $flow = static::bindPodFlagsToFlow($config, $flow, $order);
        $flow = static::executeLogicStack($flow, $order);

        return $flow;
    }

    /**
     * Get an order activity from an order configuration provided by the index.
     */
    public static function getActivity(Order $order, $index): ?array
    {
        $flow = static::getOrderConfigFlow($order);

        return $flow[$index] ?? null;
    }

    /**
     * Get an order activity from an order configuration provided by the index.
     */
    public static function getActivityByIndex(Order $order, int $index): ?array
    {
        $flow   = array_values(static::getOrderConfigFlow($order));
        $config = $flow[$index] ?? null;

        return Arr::first(data_get($config, 'events', []));
    }

    /**
     * Get an order activity from an order configuration provided by the index.
     */
    public static function getAfterNextActivity(Order $order): ?array
    {
        $current    = strtolower($order->status);
        $flow       = static::getOrderConfigFlow($order);
        $keys       = array_keys($flow);
        $activities = array_values($flow);
        $index      = array_search($current, $keys) + 1;
        $config     = $activities[$index] ?? null;

        return Arr::first(data_get($config, 'events', []));
    }

    /**
     * Get the next sequential activity from an order configuration.
     */
    public static function getNextActivity(Order $order): ?array
    {
        $flow = static::getOrderFlow($order);

        return Arr::first($flow);
    }

    /**
     * Returns a order type configuration by key.
     */
    public static function getDispatchActivity(Order $order): ?array
    {
        $flow = static::getOrderFlow($order);

        return collect($flow)->first(function ($activity) {
            return isset($activity['code']) && $activity['code'] === 'dispatched';
        });
    }

    /**
     * Returns a order flow status for the waypoint only.
     */
    public static function getOrderWaypointFlow(Order $order, Waypoint $waypoint = null): ?array
    {
        if ($waypoint === null) {
            /** @var \Fleetbase\Models\Waypoint $waypoint */
            $waypoint = $order->payload->waypointMarkers->first(function ($marker) use ($order) {
                return $marker->place_uuid === $order->payload->current_waypoint_uuid;
            });
        }

        $config = static::getOrderConfig($order);
        $vars   = static::getOrderFlowVars($order, $waypoint);
        $code   = strtolower($waypoint->status_code);

        // if code is completed return empty array
        if ($code === 'completed' || $code === 'canceled') {
            return [];
        }

        $status = data_get($config, 'meta.flow.waypoint|' . $code . '.events');

        if (!$status) {
            return static::getOrderFlow($order);
        }

        $flow = static::bindVariablesToFlow($status, $vars);
        $flow = static::bindPodFlagsToFlow($config, $flow, $order);
        $flow = static::executeLogicStack($flow, $order);

        return $flow;
    }

    /**
     * Returns a order flow status for the waypoint only.
     */
    public static function getOrderFlowVars(Order $order, Waypoint $currentWaypoint = null): ?array
    {
        $vars         = [];
        $allWaypoints = $order->payload->waypoints ?? collect();

        // set order vars
        $vars['order'] = ['public_id' => $order->public_id, 'internal_id' => $order->internal_id, 'tracking_number' => $order->tracking, 'meta' => $order->meta];

        // set pickup
        if (!empty(data_get($order, 'payload.pickup'))) {
            $vars['order']['pickup'] = $order->payload->pickup->toArray();
        }

        // set dropoff
        if (!empty(data_get($order, 'payload.dropoff'))) {
            $vars['order']['dropoff'] = $order->payload->dropoff->toArray();
        }

        // set return
        if (!empty(data_get($order, 'payload.return'))) {
            $vars['order']['return'] = $order->payload->return->toArray();
        }

        if ($currentWaypoint) {
            $currentWaypointIndex = static::findWaypointIndex($currentWaypoint, $allWaypoints);

            // set waypoint vars
            $vars['waypoint'] = $currentWaypoint->place->toArray();
            // set waypoint index vars
            $vars['waypoint']['index']        = $currentWaypointIndex;
            $vars['waypoint']['ordinalIndex'] = Utils::ordinalNumber($currentWaypointIndex);
        }

        // // storefront order add store or network about
        // if ($order->type === 'storefront' && $order->hasMeta('storefront_id')) {
        //     $storefront = Storefront::findAbout($order->getMeta('storefront_id'));

        //     if ($storefront) {
        //         $vars['storefront'] = $storefront->toArray();
        //     }
        // }

        return $vars;
    }

    public static function orderConfigRequiresPod(Order $order)
    {
        $config = static::getOrderConfig($order);

        if ($order->type === 'storefront') {
            return $order->isMeta('require_pod');
        }

        return (bool) data_get($config, 'meta.require_pod');
    }

    public static function bindPodFlagsToFlow($config, array $flows = [], ?Order $order)
    {
        $podRequired = (bool) data_get($config, 'meta.require_pod', $order->pod_required);
        $podMethod   = data_get($config, 'meta.pod_method', $order->pod_method);

        if ($order->type === 'storefront') {
            $podRequired = $order->getMeta('require_pod');
            $podMethod   = $order->getMeta('pod_method');
        }

        if ($order->pod_required === true) {
            $podRequired = true;
            $podMethod   = $order->pod_method ?? 'scan';
        }

        if ($podRequired && !$podMethod) {
            $podMethod = 'scan';
        }

        foreach ($flows as $index => $status) {
            if (isset($status['code']) && $status['code'] === 'completed') {
                $status['require_pod'] = $podRequired;
                $status['pod_method']  = $podMethod;
            }

            $flows[$index] = $status;
        }

        return $flows;
    }

    public static function bindVariablesToFlow(?array $flows, array $vars = [])
    {
        if (is_null($flows)) {
            return [];
        }

        if (!is_array($flows)) {
            return $flows;
        }

        foreach ($flows as $index => $status) {
            if (!is_array($status)) {
                continue;
            }

            foreach ($status as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }

                $status[$key] = Utils::bindVariablesToString($value, $vars);
            }

            $flows[$index] = $status;
        }

        return $flows;
    }

    public static function executeLogicStack(array $flows, Order $order)
    {
        $passingFlows = [];
        $noLogicFlows = [];

        foreach ($flows as $status) {
            if (isset($status['if']) && count($status['if'])) {
                foreach ($status['if'] as $logic) {
                    list($prop, $operator, $rightSideValue) = $logic;

                    $leftSideValue = data_get($order, $prop);
                    $passed        = false;

                    if ($operator === '=') {
                        $passed = $leftSideValue === $rightSideValue;
                    }

                    if ($operator === '!=') {
                        $passed = $leftSideValue !== $rightSideValue;
                    }

                    if ($operator === '$') {
                        $passed = Str::contains($rightSideValue, $leftSideValue);
                    }

                    if ($operator === '>') {
                        $passed = $leftSideValue > $rightSideValue;
                    }

                    if ($operator === '<') {
                        $passed = $leftSideValue < $rightSideValue;
                    }

                    if ($passed) {
                        $passingFlows[] = $status;
                    }
                }
            } else {
                $noLogicFlows[] = $status;
            }
        }

        if (!$passingFlows) {
            return $noLogicFlows;
        }

        return $passingFlows;
    }

    public static function findWaypointIndex(Waypoint $waypoint, Collection $waypoints): ?int
    {
        $waypointIndex = $waypoints->search(function ($wp) use ($waypoint) {
            return $wp->uuid === $waypoint->place_uuid;
        });

        return $waypointIndex + 1;
    }

    public static function getInstalledOrderConfigs()
    {
        $configs = Extension::where('meta_type', 'order_config')
            ->whereHas('type', function ($q) {
                $q->where('key', 'config');
            })->whereHas('installs', function ($q) {
                $q->where('company_uuid', session('company'));
            })->get();

        return $configs;
    }

    public static function getAuthoredOrderConfigs()
    {
        $configs = Extension::where('meta_type', 'order_config')
            ->whereHas('type', function ($q) {
                $q->where('key', 'config');
            })->where('author_uuid', session('company'))->get();

        return $configs;
    }

    public static function getInstalledOrderConfigsCount()
    {
        $count = Extension::where('meta_type', 'order_config')
            ->whereHas('type', function ($q) {
                $q->where('key', 'config');
            })->whereHas('installs', function ($q) {
                $q->where('company_uuid', session('company'));
            })->count();

        return $count;
    }

    public static function getAuthoredOrderConfigsCount()
    {
        $count = Extension::where('meta_type', 'order_config')
            ->whereHas('type', function ($q) {
                $q->where('key', 'config');
            })->where('author_uuid', session('company'))->count();

        return $count;
    }

    public static function hasInstalledOrAuthoredOrderConfigs()
    {
        $installed = static::getInstalledOrderConfigsCount();
        $authored  = static::getAuthoredOrderConfigsCount();

        return ($installed + $authored) > 0;
    }

    public static function getDriverFromToken()
    {
        $token = request()->header('Driver-Token');

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken && Str::isUuid($accessToken->name)) {
                $driver = Driver::where('uuid', $accessToken->name)->first();

                if ($driver) {
                    return $driver;
                }
            }

            if ($accessToken) {
                return Driver::where('user_uuid', $accessToken->tokenable->uuid)->first();
            }
        }

        return null;
    }

    public static function getCompanySession()
    {
        return Company::currentSession();
    }

    public static function getCompanySessionForUser(User $user)
    {
        return Company::where('uuid', $user->company_uuid)->first();
    }

    public static function getDefaultOrderFlow()
    {
        return config('api.types.order.0.meta.flow');
    }

    public static function getDefaultOrderConfig(): Extension
    {
        $name        = 'Default';
        $description = 'Operational flow for standard A to B transport.';
        $company     = static::getCompanySession();

        return new Extension([
            'id'           => -1,
            'uuid'         => Str::uuid(),
            'author_uuid'  => $company->uuid,
            'name'         => $name,
            'description'  => $description,
            'display_name' => $name,
            'key'          => Str::slug($name),
            'namespace'    => Extension::createNamespace($company->slug, 'order-config', $name),
            'version'      => '0.0.1',
            'core_service' => 0,
            'meta'         => ['flow' => static::getDefaultOrderFlow()],
            'meta_type'    => 'order_config',
            'config'       => [],
            'status'       => 'private',
        ]);
    }

    public static function getAllDefaultOrderConfigs(): Collection
    {
        /** @var \Illuminate\Support\Collection $extensions */
        $extensions             = collect();
        $company                = static::getCompanySession();
        $orderConfigs           = config('api.types.order', []);
        $thirdPartyOrderConfigs = Utils::fromFleetbaseExtensions('order-config');

        // make sure order configs is array
        if (!is_array($orderConfigs)) {
            $orderConfigs = [];
        }

        if (!empty($thirdPartyOrderConfigs)) {
            foreach ($thirdPartyOrderConfigs as $orderConfigProvider) {
                $resolvedOrderConfigProvider = app($orderConfigProvider);

                if (is_object($resolvedOrderConfigProvider) && method_exists($resolvedOrderConfigProvider, 'get')) {
                    $orderConfigs = array_merge($orderConfigs, $resolvedOrderConfigProvider->get());
                }
            }
        }

        // convert order configs to local extensions
        foreach ($orderConfigs as $index => $orderConfig) {
            $extensions->push(
                new Extension(
                    [
                        'id'           => $index,
                        'uuid'         => Str::uuid(),
                        'author_uuid'  => $company->uuid,
                        'name'         => data_get($orderConfig, 'name'),
                        'description'  => data_get($orderConfig, 'description'),
                        'display_name' => data_get($orderConfig, 'name'),
                        'key'          => data_get($orderConfig, 'key'),
                        'namespace'    => Extension::createNamespace($company->slug, 'order-config', data_get($orderConfig, 'name')),
                        'version'      => '0.0.1',
                        'core_service' => 0,
                        'meta'         => data_get($orderConfig, 'meta'),
                        'meta_type'    => 'order_config',
                        'config'       => [],
                        'status'       => 'private',
                    ]
                )
            );
        }

        return $extensions;
    }
}
