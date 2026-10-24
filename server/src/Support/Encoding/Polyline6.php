<?php

namespace Fleetbase\FleetOps\Support\Encoding;

/**
 * Polyline encoding at 1e-6 precision, the shape format Valhalla returns.
 */
class Polyline6 extends Polyline
{
    protected static $precision = 6;
}
