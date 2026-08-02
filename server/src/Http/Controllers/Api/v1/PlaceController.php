<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreatePlaceRequest;
use Fleetbase\FleetOps\Http\Requests\UpdatePlaceRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\PlaceSearch;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Geocoder\Laravel\Facades\Geocoder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlaceController extends Controller
{
    /**
     * Creates a new Fleetbase Place resource.
     *
     * @param \Fleetbase\Http\Requests\CreatePlaceRequest $request
     *
     * @return \Fleetbase\Http\Resources\Place
     */
    public function create(CreatePlaceRequest $request)
    {
        // get request input
        $input = $this->placeInputFromRequest($request);

        // Check is missing key address attributes
        $isNotAddressObject = $this->isNotAddressObject($request);

        // if address param is sent create from mixed
        if ($isNotAddressObject && $request->isString('address')) {
            $place = $this->createPlaceFromGeocodingLookup($request->input('address'));

            if ($place instanceof Place) {
                $input = $place->toArray();
            }
        }

        // if street1 is the only param
        if ($isNotAddressObject && $request->isString('street1')) {
            $place = $this->createPlaceFromGeocodingLookup($request->input('street1'));

            if ($place instanceof Place) {
                $input = $place->toArray();
            }
        }

        // if we have only latitude/longitude or location BUT no street1 do a reverse lookup
        $requestHasCoordinates = $request->filled(['latitude', 'longitude']);
        $requestHasLocation    = $request->filled(['location']);
        $requestMissingStreet  = $request->missing('street1');
        if ($requestMissingStreet && ($requestHasCoordinates || $requestHasLocation)) {
            $point = $this->pointFromCoordinateRequest($request);

            if ($point instanceof Point) {
                $place = $this->createPlaceFromReverseGeocodingLookup($point);

                if ($place instanceof Place) {
                    $input = $place->toArray();
                }
            }
        }

        // latitude / longitude
        $input = $this->withLocationFromRequest($input, $request);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // owner assignment
        if ($request->has('owner')) {
            $id = $request->input('owner');

            // check if customer_ based contact
            if (Str::startsWith($id, 'customer')) {
                $id = Str::replaceFirst('customer', 'contact', $id);
            }

            $owner = $this->getUuid(
                ['contacts', 'vendors'],
                [
                    'public_id'    => $id,
                    'company_uuid' => session('company'),
                ],
                [
                    'with_table' => true,
                ]
            );

            if (is_array($owner)) {
                $input['owner_uuid'] = $this->getValue($owner, 'uuid');
                $input['owner_type'] = $this->getModelClassName($this->getValue($owner, 'table'));
            }
        }

        /** @var \Fleetbase\Models\Place */
        $place = $this->firstOrNewPlace([
            'company_uuid' => session('company'),
            'owner_uuid'   => data_get($input, 'owner_uuid'),
            'name'         => strtoupper($this->orValue($input, ['name', 'street1'])),
            'street1'      => strtoupper($input['street1']),
        ]);

        // check if missing location
        // set a default location for creation
        $isMissingLocation = empty($input['location']);
        if ($isMissingLocation) {
            $input['location'] = new Point(0, 0);
        }

        // fill place with attributes
        $place->fill($input);

        // attempt to find and set latitude and longitude
        if ($isMissingLocation && $request->missing(['latitude', 'longitude', 'location'])) {
            $geocoded = $this->geocodeAddress($place->toAddressString(['name']));

            if ($geocoded) {
                $place->fillWithGoogleAddress($geocoded);
            } elseif (isset($place->location)) {
                $place->location = new Point(0, 0);
            }
        }

        // Save place
        $place->save();

        return $this->placeResource($place);
    }

    /**
     * Updates a Fleetbase Place resource.
     *
     * @param string                                      $id
     * @param \Fleetbase\Http\Requests\UpdatePlaceRequest $request
     *
     * @return \Fleetbase\Http\Resources\Place
     */
    public function update($id, UpdatePlaceRequest $request)
    {
        try {
            $place = $this->findPlaceOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->apiError('Place resource not found.');
        }

        // get request input
        $input = $this->placeInputFromRequest($request);

        // latitude / longitude
        $input = $this->withLocationFromRequest($input, $request);

        // owner assignment
        if ($request->has('owner')) {
            $owner = $request->input('owner');

            // Handle if owner is an object
            if (is_array($owner) || is_object($owner)) {
                $id = data_get($owner, 'id', data_get($owner, 'customer_id'));
            } elseif (is_string($owner)) {
                $id = $owner;
            }

            if ($id) {
                // check if customer_ based contact
                if (Str::startsWith($id, 'customer')) {
                    $id = Str::replaceFirst('customer', 'contact', $id);
                }

                $owner = $this->getUuid(
                    ['contacts', 'vendors'],
                    [
                        'public_id'    => $id,
                        'company_uuid' => session('company'),
                    ],
                    [
                        'with_table' => true,
                    ]
                );

                if (is_array($owner)) {
                    $input['owner_uuid'] = $this->getValue($owner, 'uuid');
                    $input['owner_type'] = $this->getModelClassName($this->getValue($owner, 'table'));
                }
            }
        }

        // vendor assignment
        if ($request->has('vendor')) {
            $input['vendor_uuid'] = $this->getUuid('vendors', [
                'public_id'    => $request->input('vendor'),
                'company_uuid' => session('company'),
            ]);
        }

        // update the place
        $place->update($input);
        $place->flushAttributesCache();

        return $this->placeResource($place);
    }

    /**
     * Query for Fleetbase Place resources.
     *
     * @return \Fleetbase\Http\Resources\PlaceCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryPlaces($request, function (&$query, $request) {
            if ($request->has('vendor')) {
                $query->whereHas('vendor', function ($q) use ($request) {
                    $q->where('public_id', $request->input('vendor'));
                });
            }
        });

        $results = $results->all();

        return $this->placeResourceCollection($results);
    }

    /**
     * Search for Fleetbase Place resources.
     *
     * @return \Fleetbase\Http\Resources\PlaceCollection
     */
    public function search(Request $request)
    {
        $searchQuery = strtolower($request->input('query'));
        $options     = $this->placeSearchOptionsFromRequest($request);

        $query = $this->basePlaceSearchQuery();

        $results = $this->searchPlaces($query, $searchQuery, $options);

        return $this->placeResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase Place resources.
     *
     * @return \Fleetbase\Http\Resources\Place
     */
    public function find($id, Request $request)
    {
        // find for the place
        try {
            $place = $this->findPlaceOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->apiError('Place resource not found.');
        }

        return $this->placeResource($place);
    }

    /**
     * Deletes a Fleetbase Place resources.
     *
     * @return \Fleetbase\Http\Resources\Place
     */
    public function delete($id, Request $request)
    {
        try {
            $place = $this->findPlaceOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->apiError('Place resource not found.');
        }

        $place->delete();

        return $this->deletedPlaceResource($place);
    }

    protected function placeInputFromRequest(Request $request): array
    {
        return $request->only([
            'name',
            'street1',
            'street2',
            'city',
            'location',
            'province',
            'postal_code',
            'neighborhood',
            'district',
            'building',
            'security_access_code',
            'country',
            'phone',
            'type',
            'meta',
        ]);
    }

    protected function isNotAddressObject(Request $request): bool
    {
        return $request->isNotFilled(['name', 'location', 'latititude', 'longitude', 'city', 'province', 'postal_code']);
    }

    protected function pointFromCoordinateRequest(Request $request): ?Point
    {
        if ($request->filled(['latitude', 'longitude'])) {
            return $this->pointFromMixed($request->only(['latitude', 'longitude']));
        }

        if ($request->filled(['location'])) {
            return $this->pointFromMixed($request->input('location'));
        }

        return null;
    }

    protected function withLocationFromRequest(array $input, Request $request): array
    {
        if ($request->has(['latitude', 'longitude'])) {
            $input['location'] = $this->pointFromCoordinates($request->only(['latitude', 'longitude']));
        } elseif ($request->filled(['location'])) {
            $input['location'] = $this->pointFromMixed($request->input('location'));
        }

        return $input;
    }

    protected function placeSearchOptionsFromRequest(Request $request): array
    {
        return [
            'limit'          => $request->input('limit', 10),
            'geo'            => $request->boolean('geo'),
            'latitude'       => $request->input('latitude', false),
            'longitude'      => $request->input('longitude', false),
            'no_query_order' => 'name_desc',
        ];
    }

    protected function createPlaceFromGeocodingLookup(string $address): ?Place
    {
        return Place::createFromGeocodingLookup($address);
    }

    protected function createPlaceFromReverseGeocodingLookup(Point $point): ?Place
    {
        return Place::createFromReverseGeocodingLookup($point);
    }

    protected function getUuid(array|string $table, array $where, array $options = []): mixed
    {
        return Utils::getUuid($table, $where, $options);
    }

    protected function getValue(array|object $data, string $key): mixed
    {
        return Utils::get($data, $key);
    }

    protected function getModelClassName(?string $table): ?string
    {
        return $table ? Utils::getModelClassName($table) : null;
    }

    protected function orValue(array $input, array $keys): mixed
    {
        return Utils::or($input, $keys);
    }

    protected function firstOrNewPlace(array $attributes): Place
    {
        return Place::firstOrNew($attributes);
    }

    protected function geocodeAddress(string $address): mixed
    {
        return Geocoder::geocode($address)->get()->first();
    }

    protected function findPlaceOrFail(string $id): Place
    {
        return Place::findRecordOrFail($id);
    }

    protected function queryPlaces(Request $request, callable $callback): mixed
    {
        return Place::queryWithRequest($request, $callback);
    }

    protected function basePlaceSearchQuery(): mixed
    {
        return Place::where('company_uuid', session('company'))->whereNull('deleted_at');
    }

    protected function searchPlaces(mixed $query, string $searchQuery, array $options): mixed
    {
        return PlaceSearch::search($query, $searchQuery, $options);
    }

    protected function pointFromMixed(mixed $input): ?Point
    {
        return Utils::getPointFromMixed($input);
    }

    protected function pointFromCoordinates(array $coordinates): ?Point
    {
        return Utils::getPointFromCoordinates($coordinates);
    }

    protected function placeResource(Place $place)
    {
        return new PlaceResource($place);
    }

    protected function placeResourceCollection(mixed $results): mixed
    {
        return PlaceResource::collection($results);
    }

    protected function deletedPlaceResource(Place $place)
    {
        return new DeletedResource($place);
    }

    protected function apiError(string $message, int $status = 400)
    {
        return response()->apiError($message, $status);
    }
}
