<?php

namespace Fleetbase\FleetOps\Integrations\UPS;

#[\AllowDynamicProperties]
class UPSServiceType
{
    /**
     * Available UPS service levels for Mode B (direct carrier) rating.
     * service_code values are UPS's numeric Service.Code identifiers
     * used in Rate Shop / Ship API requests.
     */
    private static array $serviceTypes = [
        ['key' => 'GROUND',       'description' => 'UPS Ground',              'service_code' => '03', 'carrier' => 'UPS'],
        ['key' => 'GROUND_SAVER', 'description' => 'UPS Ground Saver',        'service_code' => '93', 'carrier' => 'UPS'],
        ['key' => '3DS',          'description' => 'UPS 3 Day Select',        'service_code' => '12', 'carrier' => 'UPS'],
        ['key' => '2DA',          'description' => 'UPS 2nd Day Air',         'service_code' => '02', 'carrier' => 'UPS'],
        ['key' => '2DAM',         'description' => 'UPS 2nd Day Air A.M.',    'service_code' => '59', 'carrier' => 'UPS'],
        ['key' => '1DA',          'description' => 'UPS Next Day Air',        'service_code' => '01', 'carrier' => 'UPS'],
        ['key' => '1DAM',         'description' => 'UPS Next Day Air Early',  'service_code' => '14', 'carrier' => 'UPS'],
        ['key' => '1DASAVER',     'description' => 'UPS Next Day Air Saver',  'service_code' => '13', 'carrier' => 'UPS'],
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
            return collect(static::$serviceTypes)->mapInto(UPSServiceType::class);
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
        return collect(static::$serviceTypes)->mapInto(UPSServiceType::class);
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
