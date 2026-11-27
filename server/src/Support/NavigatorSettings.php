<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Models\Setting;

/**
 * An organization's choices for its drivers' Navigator app that are not about
 * onboarding or field editing. Every switch has a default, so an organization
 * that has never opened the settings page behaves as before.
 */
class NavigatorSettings
{
    public const PREFIX = 'fleet-ops.navigator-app-settings.';

    public const DEFAULTS = [
        // Whether drivers see what an order is charged at (its service rate and lines).
        'showServiceRateToDrivers' => true,
    ];

    public static function get(string $companyUuid): array
    {
        $stored = Setting::where('key', static::PREFIX . $companyUuid)->value('value');

        return static::normalize(is_array($stored) ? $stored : []);
    }

    public static function save(string $companyUuid, array $settings): array
    {
        $settings = static::normalize($settings);
        Setting::configure(static::PREFIX . $companyUuid, $settings);

        return $settings;
    }

    /**
     * Known keys only, each as a boolean, missing ones at their default.
     */
    public static function normalize(array $settings): array
    {
        $normalized = [];
        foreach (static::DEFAULTS as $key => $default) {
            $normalized[$key] = array_key_exists($key, $settings) ? filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN) : $default;
        }

        return $normalized;
    }

    /**
     * The settings as the driver app reads them.
     */
    public static function forDriverApp(string $companyUuid): array
    {
        $settings = static::get($companyUuid);

        return [
            'show_service_rate' => $settings['showServiceRateToDrivers'],
            // The organization's routing distance unit (Settings → Routing), so the app shows distances the way the console does.
            'distance_unit'     => static::distanceUnit($companyUuid),
        ];
    }

    /**
     * `km` or `mi`, from the organization's routing settings; `km` when it never chose.
     */
    public static function distanceUnit(string $companyUuid): string
    {
        try {
            $routing = Setting::lookupForCompany($companyUuid, 'routing', ['unit' => 'km']);
        } catch (\Throwable $e) {
            $routing = null;
        }

        $unit = strtolower(trim((string) data_get($routing, 'unit', 'km')));

        return in_array($unit, ['mi', 'mile', 'miles', 'imperial'], true) ? 'mi' : 'km';
    }
}
