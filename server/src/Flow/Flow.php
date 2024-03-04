<?php

namespace Fleetbase\FleetOps\Flow;

use Illuminate\Support\Str;

class Flow
{
    public array $attributes = [];

    public function __constructor(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function get($key, $defaultValue = null)
    {
        $methodKey = 'get' . Str::capitalize($key) . 'Attribute';
        if (method_exists($this, $methodKey)) {
            return $this->{$methodKey}();
        }

        $value = $this->attributes[$key];
        if (!$value) {
            return $defaultValue;
        }

        return $value;
    }

    public function set(string $key, $value)
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function serialize()
    {
        return $this->attributes;
    }
}
