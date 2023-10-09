<?php

namespace Fleetbase\FleetOps\Casts;

use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Grimzy\LaravelMysqlSpatial\Types\Polygon as PolygonType;
use Grimzy\LaravelMysqlSpatial\Types\GeometryInterface;
use Grimzy\LaravelMysqlSpatial\Eloquent\SpatialExpression;

class Polygon implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return mixed
     */
    public function get($model, $key, $value, $attributes)
    {
        return $value;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return mixed
     */
    public function set($model, $key, $value, $attributes)
    {
        if ($value instanceof GeometryInterface) {
            $model->geometries[$key] = $value;

            return new SpatialExpression($value);
        }

        if (Utils::isGeoJson($value)) {
            return Utils::createSpatialExpressionFromGeoJson($value);
        }

        return new SpatialExpression(new PolygonType([]));
    }
}
