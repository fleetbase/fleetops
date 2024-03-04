<?php

namespace Fleetbase\FleetOps\Flow;

class Activity extends Flow
{
    public array $attributes = [];

    public function __constructor(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function getLogicAttribute()
    {
        return array_map(
            function ($logic) {
                return new Logic($logic);
            },
            $this->get('logic', [])
        );
    }
}
