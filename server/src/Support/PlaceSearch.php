<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Geocoder\Laravel\Facades\Geocoder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PlaceSearch
{
    public static function search(Builder $query, ?string $searchQuery = null, array $options = []): Collection
    {
        $searchQuery  = static::normalizeSearchQuery($searchQuery);
        $limit        = (int) data_get($options, 'limit', 30);
        $geo          = filter_var(data_get($options, 'geo', false), FILTER_VALIDATE_BOOLEAN);
        $latitude     = data_get($options, 'latitude');
        $longitude    = data_get($options, 'longitude');
        $noQueryOrder = data_get($options, 'no_query_order', 'name_desc');

        static::applySavedPlaceSearch($query, $searchQuery, $latitude, $longitude, $noQueryOrder);

        if ($limit > 0) {
            $query->limit($limit);
        }

        $savedPlaces = $query->get();
        $geoPlaces   = $geo ? static::geocode($searchQuery, $latitude, $longitude) : collect();

        return static::mergeResults($geoPlaces, $savedPlaces, $searchQuery);
    }

    public static function geocode(?string $searchQuery = null, $latitude = null, $longitude = null): Collection
    {
        $searchQuery = static::normalizeSearchQuery($searchQuery);

        if (!$searchQuery && (!$latitude || !$longitude)) {
            return collect();
        }

        try {
            if (Geocoding::canGoogleGeocode()) {
                $results = collect();

                try {
                    if ($searchQuery) {
                        $results = $results->merge(Geocoding::geocode($searchQuery, $latitude, $longitude));
                    } elseif ($latitude && $longitude) {
                        $results = $results->merge(Geocoding::reverseFromCoordinates($latitude, $longitude));
                    }
                } catch (\Throwable) {
                    // Fall through to any nearby results already collected.
                }

                if ($searchQuery && $latitude && $longitude) {
                    try {
                        $results = $results->merge(Geocoding::reverseFromQuery($searchQuery, $latitude, $longitude));
                    } catch (\Throwable) {
                        // Text geocoding results should still be usable if nearby lookup fails.
                    }
                }

                return static::rankPlacesByQuery(static::uniquePlaces($results), $searchQuery);
            }

            if (!$searchQuery) {
                return collect();
            }

            $results = Geocoder::geocode($searchQuery)
                ->get()
                ->map(fn ($googleAddress) => Place::createFromGoogleAddress($googleAddress))
                ->values();

            return static::rankPlacesByQuery($results, $searchQuery);
        } catch (\Throwable) {
            return collect();
        }
    }

    protected static function applySavedPlaceSearch(Builder $query, ?string $searchQuery, $latitude = null, $longitude = null, string $noQueryOrder = 'name_desc'): void
    {
        $query->addSelect('places.*');

        if ($searchQuery) {
            $query->where(function ($query) use ($searchQuery) {
                $contains = '%' . $searchQuery . '%';

                $query->orWhereRaw('LOWER(COALESCE(places.name, "")) LIKE ?', [$contains])
                    ->orWhereRaw('LOWER(COALESCE(places.street1, "")) LIKE ?', [$contains])
                    ->orWhereRaw('LOWER(COALESCE(places.street2, "")) LIKE ?', [$contains])
                    ->orWhereRaw('LOWER(COALESCE(places.city, "")) LIKE ?', [$contains])
                    ->orWhereRaw('LOWER(COALESCE(places.province, "")) LIKE ?', [$contains])
                    ->orWhereRaw('LOWER(COALESCE(places.postal_code, "")) LIKE ?', [$contains]);
            });

            $prefix   = $searchQuery . '%';
            $contains = '%' . $searchQuery . '%';

            $query->selectRaw(
                'CASE
                    WHEN LOWER(COALESCE(places.name, "")) = ? OR LOWER(COALESCE(places.street1, "")) = ? OR LOWER(COALESCE(places.postal_code, "")) = ? THEN 0
                    WHEN LOWER(COALESCE(places.name, "")) LIKE ? OR LOWER(COALESCE(places.street1, "")) LIKE ? OR LOWER(COALESCE(places.postal_code, "")) LIKE ? THEN 1
                    WHEN LOWER(COALESCE(places.name, "")) LIKE ? OR LOWER(COALESCE(places.street1, "")) LIKE ? OR LOWER(COALESCE(places.street2, "")) LIKE ? OR LOWER(COALESCE(places.city, "")) LIKE ? OR LOWER(COALESCE(places.province, "")) LIKE ? OR LOWER(COALESCE(places.postal_code, "")) LIKE ? THEN 2
                    ELSE 3
                END AS place_search_relevance',
                [$searchQuery, $searchQuery, $searchQuery, $prefix, $prefix, $prefix, $contains, $contains, $contains, $contains, $contains, $contains]
            );
            $query->orderBy('place_search_relevance', 'asc');
        }

        if ($latitude && $longitude) {
            $point = new Point($latitude, $longitude);

            $query->whereNotNull('places.location')->whereRaw('
                ST_Y(places.location) BETWEEN -90 AND 90
                AND ST_X(places.location) BETWEEN -180 AND 180
                AND NOT (ST_X(places.location) = 0 AND ST_Y(places.location) = 0)
            ');
            $query->orderByDistanceSphere('location', $point, 'asc');
        } elseif (!$searchQuery && $noQueryOrder === 'latest') {
            $query->orderBy('places.created_at', 'desc');
        } else {
            $query->orderBy('places.name', 'desc');
        }
    }

    protected static function mergeResults(Collection $geoPlaces, Collection $savedPlaces, ?string $searchQuery = null): Collection
    {
        $merged  = [];
        $indexes = [];

        foreach ($geoPlaces as $place) {
            static::pushUniquePlace($merged, $indexes, $place);
        }

        foreach ($savedPlaces as $place) {
            $key = static::placeKey($place);

            if ($key && isset($indexes[$key])) {
                if (static::isStrongSavedMatch($place, $searchQuery)) {
                    $merged[$indexes[$key]] = $place;
                }

                continue;
            }

            static::pushUniquePlace($merged, $indexes, $place);
        }

        return collect($merged)->values();
    }

    protected static function pushUniquePlace(array &$merged, array &$indexes, ?Place $place): void
    {
        if (!$place instanceof Place) {
            return;
        }

        $key = static::placeKey($place);

        if ($key && isset($indexes[$key])) {
            return;
        }

        if ($key) {
            $indexes[$key] = count($merged);
        }

        $merged[] = $place;
    }

    protected static function uniquePlaces(Collection $places): Collection
    {
        $merged  = [];
        $indexes = [];

        foreach ($places as $place) {
            static::pushUniquePlace($merged, $indexes, $place);
        }

        return collect($merged)->values();
    }

    protected static function rankPlacesByQuery(Collection $places, ?string $searchQuery = null): Collection
    {
        if (!$searchQuery) {
            return $places->values();
        }

        return $places
            ->values()
            ->map(fn ($place, $index) => [
                'place' => $place,
                'rank'  => static::placeQueryRank($place, $searchQuery),
                'index' => $index,
            ])
            ->sortBy([
                ['rank', 'asc'],
                ['index', 'asc'],
            ])
            ->pluck('place')
            ->values();
    }

    protected static function placeQueryRank(Place $place, ?string $searchQuery = null): int
    {
        if (!$searchQuery) {
            return 4;
        }

        $query  = static::normalizeSearchQuery($searchQuery);
        $values = collect([
            $place->name,
            $place->street1,
            $place->postal_code,
            $place->toAddressString(['name']),
            $place->address,
        ])
            ->filter()
            ->map(fn ($value) => static::normalizeSearchQuery($value));

        if ($values->contains($query)) {
            return 0;
        }

        if ($values->contains(fn ($value) => Str::startsWith($value, $query))) {
            return 1;
        }

        if ($values->contains(fn ($value) => Str::contains($value, $query))) {
            return 2;
        }

        return 3;
    }

    protected static function isStrongSavedMatch(Place $place, ?string $searchQuery = null): bool
    {
        if (!$searchQuery) {
            return false;
        }

        $query = static::normalizeSearchQuery($searchQuery);

        return collect([$place->name, $place->street1, $place->postal_code])
            ->filter()
            ->map(fn ($value) => static::normalizeSearchQuery($value))
            ->contains($query);
    }

    protected static function placeKey(Place $place): ?string
    {
        $address = static::normalizeSearchQuery($place->toAddressString(['name']) ?: $place->address ?: $place->street1 ?: $place->name);
        $lat     = data_get($place, 'latitude');
        $lng     = data_get($place, 'longitude');

        if (!$lat && $place->location && method_exists($place->location, 'getLat')) {
            $lat = $place->location->getLat();
            $lng = $place->location->getLng();
        }

        $coordinates = $lat && $lng ? round((float) $lat, 5) . ',' . round((float) $lng, 5) : null;

        if (!$address && !$coordinates) {
            return null;
        }

        return trim($address . '|' . $coordinates, '|');
    }

    protected static function normalizeSearchQuery(?string $searchQuery): ?string
    {
        if (!is_string($searchQuery)) {
            return null;
        }

        return (string) Str::of($searchQuery)->lower()->squish();
    }
}
