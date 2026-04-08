<?php

namespace Fleetbase\FleetOps\Integrations\ParcelPath;

#[\AllowDynamicProperties]
class ParcelPathServiceType
{
    /**
     * Available Service types for ParcelPath (UPS + USPS via ParcelPath v9 API).
     */
    private static array $serviceTypes = [
        ['key' => 'PP_UPS_GROUND',       'description' => 'UPS Ground',             'carrier' => 'UPS',  'pp_v9' => 'ups_ground'],
        ['key' => 'PP_UPS_GROUND_SAVER', 'description' => 'UPS Ground Saver',       'carrier' => 'UPS',  'pp_v9' => 'ups_ground_saver'],
        ['key' => 'PP_UPS_3DS',          'description' => 'UPS 3 Day Select',       'carrier' => 'UPS',  'pp_v9' => 'ups_3_day_select'],
        ['key' => 'PP_UPS_2DA',          'description' => 'UPS 2nd Day Air',        'carrier' => 'UPS',  'pp_v9' => 'ups_2nd_day_air'],
        ['key' => 'PP_UPS_2DAM',         'description' => 'UPS 2nd Day Air A.M.',   'carrier' => 'UPS',  'pp_v9' => 'ups_2nd_day_air_am'],
        ['key' => 'PP_UPS_1DA',          'description' => 'UPS Next Day Air',       'carrier' => 'UPS',  'pp_v9' => 'ups_next_day_air'],
        ['key' => 'PP_UPS_1DAM',         'description' => 'UPS Next Day Air Early', 'carrier' => 'UPS',  'pp_v9' => 'ups_next_day_air_early'],
        ['key' => 'PP_UPS_1DASAVER',     'description' => 'UPS Next Day Air Saver', 'carrier' => 'UPS',  'pp_v9' => 'ups_next_day_air_saver'],
        ['key' => 'PP_USPS_PRIORITY',    'description' => 'USPS Priority Mail',         'carrier' => 'USPS', 'pp_v9' => 'Priority'],
        ['key' => 'PP_USPS_EXPRESS',     'description' => 'USPS Priority Mail Express', 'carrier' => 'USPS', 'pp_v9' => 'Express'],
        ['key' => 'PP_USPS_GROUND_ADV',  'description' => 'USPS Ground Advantage',      'carrier' => 'USPS', 'pp_v9' => 'GroundAdvantage'],
        ['key' => 'PP_USPS_FIRST',       'description' => 'USPS First Class',           'carrier' => 'USPS', 'pp_v9' => 'First'],
        ['key' => 'PP_USPS_MEDIA',       'description' => 'USPS Media Mail',            'carrier' => 'USPS', 'pp_v9' => 'MediaMail'],
    ];

    public function __construct(array $details)
    {
        foreach ($details as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function __get(string $key)
    {
        if (isset($this->{$key})) {
            return $this->{$key};
        }

        return null;
    }

    public function __call(string $key, $arguments)
    {
        if ($key === 'all') {
            return collect(static::$serviceTypes)->mapInto(ParcelPathServiceType::class);
        }

        if (method_exists($this, $key)) {
            $this->{$key}(...$arguments);
        }

        return null;
    }

    public function getKey()
    {
        return $this->key;
    }

    public static function all()
    {
        return collect(static::$serviceTypes)->mapInto(ParcelPathServiceType::class);
    }

    public static function find($key)
    {
        if (is_callable($key)) {
            return static::all()->first($key);
        }

        if (is_string($key)) {
            return static::all()->first(function ($detail) use ($key) {
                return isset($detail->key) && strcasecmp($detail->key, $key) === 0;
            });
        }

        return null;
    }
}
