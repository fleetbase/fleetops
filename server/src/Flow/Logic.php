<?php

namespace Fleetbase\FleetOps\Flow;

class Logic extends Flow
{
    public function getConditionsAttribute()
    {
        return array_map(
            function ($condition) {
                return new Condition($condition);
            },
            $this->get('conditions', [])
        );
    }
}
