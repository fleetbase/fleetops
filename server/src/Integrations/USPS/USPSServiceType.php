<?php

namespace Fleetbase\FleetOps\Integrations\USPS;

#[\AllowDynamicProperties]
class USPSServiceType
{
    /**
     * USPS service levels for Mode B (direct carrier) rating and label
     * purchase via USPS Web Tools v3. mail_class values are the USPS v3
     * API identifiers expected in the /prices/v3 and /labels/v3
     * endpoints.
     */
    private static array $serviceTypes = [
        ['key' => 'PRIORITY',         'description' => 'USPS Priority Mail',              'mail_class' => 'PRIORITY_MAIL',               'carrier' => 'USPS'],
        ['key' => 'PRIORITY_EXPRESS', 'description' => 'USPS Priority Mail Express',      'mail_class' => 'PRIORITY_MAIL_EXPRESS',       'carrier' => 'USPS'],
        ['key' => 'GROUND_ADVANTAGE', 'description' => 'USPS Ground Advantage',           'mail_class' => 'USPS_GROUND_ADVANTAGE',       'carrier' => 'USPS'],
        ['key' => 'FIRST_CLASS',      'description' => 'USPS First Class Package Service', 'mail_class' => 'FIRST-CLASS_PACKAGE_SERVICE', 'carrier' => 'USPS'],
        ['key' => 'MEDIA_MAIL',       'description' => 'USPS Media Mail',                 'mail_class' => 'MEDIA_MAIL',                  'carrier' => 'USPS'],
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
            return collect(static::$serviceTypes)->mapInto(USPSServiceType::class);
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
        return collect(static::$serviceTypes)->mapInto(USPSServiceType::class);
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
