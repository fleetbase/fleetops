<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Grimzy\LaravelMysqlSpatial\Types\Geometry;

class PlaceFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $searchQuery)
    {
        $this->builder->search($searchQuery);
    }

    public function internalId(?string $internalId)
    {
        $this->builder->searchWhere('internal_id', $internalId);
    }

    public function publicId(?string $publicId)
    {
        $this->builder->searchWhere('public_id', $publicId);
    }

    public function phone(?string $phone)
    {
        $this->builder->searchWhere('phone', $phone);
    }

    public function email(?string $email)
    {
        $this->builder->searchWhere('email', $email);
    }

    public function country(?string $country)
    {
        $this->builder->where('country', $country);
    }

    public function within($within)
    {
        // search for places within a radius of center coordinates
        $center = Utils::getPointFromMixed($within);

        // get the radius
        $radius = data_get($within, 'radius', 20); // 20km

        // Convert the radius to degrees if using geographical coordinates
        $radiusInDegrees = $radius / 111.32;

        // Create a query to find places within the circle
        $this->builder->whereRaw('ST_Within(location, ST_Buffer(ST_GeomFromText(?), ?))', [
            $center->toWKT(),
            $radiusInDegrees,
        ]);
    }

    public function nearby($nearby)
    {
        // search for places within a radius of center coordinates
        $center = Utils::getPointFromMixed($nearby);

        // get the radius
        $radius = data_get($nearby, 'radius', 5); // 5km

        // Convert the radius to degrees if using geographical coordinates
        $radiusInDegrees = $radius / 111.32;

        // Create a query to find places within the circle
        $this->builder->whereRaw('ST_Within(location, ST_Buffer(ST_GeomFromText(?), ?))', [
            $center->toWKT(),
            $radiusInDegrees,
        ]);
    }

    public function withinServiceArea($serviceAreaId)
    {
        $serviceArea = ServiceArea::where('uuid', $serviceAreaId)->orWhere('public_id', $serviceAreaId)->first('border');

        if ($serviceArea->border instanceof Geometry) {
            $this->builder->within('location', $serviceArea->border);
        }
    }

    public function serviceArea($serviceAreaId)
    {
        $this->withinServiceArea($serviceAreaId);
    }

    public function withinZone($zoneId)
    {
        $zone = Zone::where('uuid', $zoneId)->orWhere('public_id', $zoneId)->first('border');

        if ($zone->border instanceof Geometry) {
            $this->builder->within('location', $zone->border);
        }
    }

    public function zone($zoneId)
    {
        $this->withinZone($zoneId);
    }
}
