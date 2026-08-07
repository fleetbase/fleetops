<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\PlaceController;
use Fleetbase\FleetOps\Http\Requests\CreatePlaceRequest;
use Fleetbase\FleetOps\Http\Requests\UpdatePlaceRequest;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiPlaceControllerProbe extends PlaceController
{
    public ?FleetOpsApiPlaceEndpointFake $place                = null;
    public ?FleetOpsApiPlaceEndpointFake $geocodedPlace        = null;
    public ?FleetOpsApiPlaceEndpointFake $reverseGeocodedPlace = null;
    public array $uuidLookups                                  = [];
    public array $firstOrNewLookups                            = [];
    public array $geocodedAddresses                            = [];
    public array $searchCalls                                  = [];
    public array $pointInputs                                  = [];
    public array $coordinateInputs                             = [];
    public mixed $queryResults                                 = null;
    public mixed $searchResults                                = null;
    public bool $placeNotFound                                 = false;

    protected function createPlaceFromGeocodingLookup(string $address): ?Place
    {
        $this->geocodedAddresses[] = ['forward', $address];

        return $this->geocodedPlace;
    }

    protected function createPlaceFromReverseGeocodingLookup(Point $point): ?Place
    {
        $this->geocodedAddresses[] = ['reverse', $point->getLat(), $point->getLng()];

        return $this->reverseGeocodedPlace;
    }

    protected function getUuid(array|string $table, array $where, array $options = []): mixed
    {
        $this->uuidLookups[] = [$table, $where, $options];

        if ($table === 'vendors') {
            return 'vendor-uuid';
        }

        return ['uuid' => 'owner-uuid', 'table' => 'contacts'];
    }

    protected function getModelClassName(?string $table): ?string
    {
        return $table === 'contacts' ? 'Fleetbase\\FleetOps\\Models\\Contact' : null;
    }

    protected function firstOrNewPlace(array $attributes): Place
    {
        $this->firstOrNewLookups[] = $attributes;

        $this->place ??= new FleetOpsApiPlaceEndpointFake();

        return $this->place;
    }

    protected function geocodeAddress(string $address): mixed
    {
        $this->geocodedAddresses[] = ['fallback', $address];

        return (object) ['address' => $address];
    }

    protected function findPlaceOrFail(string $id): Place
    {
        if ($this->placeNotFound) {
            throw new ModelNotFoundException();
        }

        $this->place ??= new FleetOpsApiPlaceEndpointFake();
        $this->place->lookupId = $id;

        return $this->place;
    }

    protected function queryPlaces(Request $request, callable $callback): mixed
    {
        $query = new FleetOpsApiPlaceQueryFake();
        $callback($query, $request);

        return new FleetOpsApiPlaceResultsFake($this->queryResults ?? [['uuid' => 'place-1']], $query->calls);
    }

    protected function basePlaceSearchQuery(): mixed
    {
        return new FleetOpsApiPlaceQueryFake();
    }

    protected function searchPlaces(mixed $query, string $searchQuery, array $options): mixed
    {
        $this->searchCalls[] = [$query, $searchQuery, $options];

        return $this->searchResults ?? [['uuid' => 'searched-place']];
    }

    protected function pointFromMixed(mixed $input): ?Point
    {
        $this->pointInputs[] = $input;

        return new Point(1.3, 103.8);
    }

    protected function pointFromCoordinates(array $coordinates): ?Point
    {
        $this->coordinateInputs[] = $coordinates;

        return new Point((float) $coordinates['latitude'], (float) $coordinates['longitude']);
    }

    protected function placeResource(Place $place): array
    {
        return ['resource' => 'place', 'place' => $place];
    }

    protected function placeResourceCollection(mixed $results): array
    {
        return ['collection' => 'place', 'items' => $results];
    }

    protected function deletedPlaceResource(Place $place): array
    {
        return ['resource' => 'deleted-place', 'place' => $place];
    }

    protected function apiError(string $message, int $status = 400): array
    {
        return ['apiError' => $message, 'status' => $status];
    }
}

class FleetOpsApiPlaceEndpointFake extends Place
{
    public array $exportedAttributes = [];
    public array $filledPayloads     = [];
    public array $updatedPayloads    = [];
    public array $googleAddresses    = [];
    public bool $savedForTest        = false;
    public bool $flushedForTest      = false;
    public bool $deletedForTest      = false;
    public ?string $lookupId         = null;
    public string $addressString     = '1 Depot Road Singapore';

    public function toArray()
    {
        return $this->exportedAttributes ?: $this->getAttributes();
    }

    public function fill(array $attributes)
    {
        $this->filledPayloads[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return $this;
    }

    public function fillWithGoogleAddress(Geocoder\Provider\GoogleMaps\Model\GoogleAddress $address): Place
    {
        $this->googleAddresses[] = $address;

        return $this;
    }

    public function save(array $options = []): bool
    {
        $this->savedForTest = true;

        return true;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updatedPayloads[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }

    public function flushAttributesCache(): bool
    {
        $this->flushedForTest = true;

        return true;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }

    public function toAddressString($except = [], $useHtml = false)
    {
        return $this->addressString;
    }
}

class FleetOpsApiPlaceQueryFake
{
    public array $calls = [];

    public function whereHas(string $relation, callable $callback): void
    {
        $nested = new self();
        $callback($nested);

        $this->calls[] = ['whereHas', $relation, $nested->calls];
    }

    public function where(string $column, mixed $value): void
    {
        $this->calls[] = ['where', $column, $value];
    }
}

class FleetOpsApiPlaceResultsFake
{
    public function __construct(public array $items, public array $queryCalls = [])
    {
    }

    public function all(): array
    {
        return ['items' => $this->items, 'query_calls' => $this->queryCalls];
    }
}

class FleetOpsUpdatePlaceEndpointRequestFake extends UpdatePlaceRequest
{
    public function __construct(private array $payload)
    {
        parent::__construct();
    }

    public function only($keys)
    {
        return collect($this->payload)->only(is_array($keys) ? $keys : func_get_args())->all();
    }

    public function has($key): bool
    {
        if (is_array($key)) {
            foreach ($key as $item) {
                if (!array_key_exists($item, $this->payload)) {
                    return false;
                }
            }

            return true;
        }

        return array_key_exists($key, $this->payload);
    }

    public function filled($key): bool
    {
        if (is_array($key)) {
            foreach ($key as $item) {
                if (!$this->filled($item)) {
                    return false;
                }
            }

            return true;
        }

        return isset($this->payload[$key]) && $this->payload[$key] !== '';
    }

    public function missing($key): bool
    {
        if (is_array($key)) {
            foreach ($key as $item) {
                if (array_key_exists($item, $this->payload)) {
                    return false;
                }
            }

            return true;
        }

        return !array_key_exists($key, $this->payload);
    }

    public function input($key = null, $default = null): mixed
    {
        if ($key === null) {
            return $this->payload;
        }

        return $this->payload[$key] ?? $default;
    }
}

class FleetOpsCreatePlaceEndpointRequestFake extends CreatePlaceRequest
{
    public function __construct(private array $payload)
    {
        parent::__construct();
    }

    public function only($keys)
    {
        return collect($this->payload)->only(is_array($keys) ? $keys : func_get_args())->all();
    }

    public function has($key): bool
    {
        if (is_array($key)) {
            foreach ($key as $item) {
                if (!array_key_exists($item, $this->payload)) {
                    return false;
                }
            }

            return true;
        }

        return array_key_exists($key, $this->payload);
    }

    public function filled($key): bool
    {
        if (is_array($key)) {
            foreach ($key as $item) {
                if (!$this->filled($item)) {
                    return false;
                }
            }

            return true;
        }

        return isset($this->payload[$key]) && $this->payload[$key] !== '';
    }

    public function missing($key): bool
    {
        if (is_array($key)) {
            foreach ($key as $item) {
                if (array_key_exists($item, $this->payload)) {
                    return false;
                }
            }

            return true;
        }

        return !array_key_exists($key, $this->payload);
    }

    public function input($key = null, $default = null): mixed
    {
        if ($key === null) {
            return $this->payload;
        }

        return $this->payload[$key] ?? $default;
    }

    public function isString($param): bool
    {
        return $this->has($param) && is_string($this->input($param));
    }

    public function isNotFilled($keys): bool
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        foreach ($keys as $key) {
            if ($this->filled($key)) {
                return false;
            }
        }

        return true;
    }
}

function fleetopsCreatePlaceRequest(array $input): CreatePlaceRequest
{
    return new FleetOpsCreatePlaceEndpointRequestFake($input);
}

function fleetopsUpdatePlaceRequest(array $input): UpdatePlaceRequest
{
    return new FleetOpsUpdatePlaceEndpointRequestFake($input);
}

function fleetopsApiPlace(array $attributes = []): FleetOpsApiPlaceEndpointFake
{
    $place      = new FleetOpsApiPlaceEndpointFake();
    $attributes = array_merge([
        'uuid'    => 'place-uuid',
        'name'    => 'Depot',
        'street1' => '1 Depot Road',
    ], $attributes);

    $place->setRawAttributes($attributes, true);
    $place->exportedAttributes = $attributes;

    return $place;
}

test('api place controller creates places from geocoded address with owner resolution', function () {
    session(['company' => 'company-uuid']);
    $controller                = new FleetOpsApiPlaceControllerProbe();
    $controller->geocodedPlace = fleetopsApiPlace([
        'name'     => 'Geocoded Depot',
        'street1'  => '1 Depot Road',
        'city'     => 'Singapore',
        'location' => new Point(1.3, 103.8),
    ]);
    $controller->place = fleetopsApiPlace();

    $response = $controller->create(fleetopsCreatePlaceRequest([
        'address' => '1 Depot Road',
        'owner'   => 'customer-public',
        'phone'   => '+15551234567',
    ]));

    expect($response['resource'])->toBe('place')
        ->and($controller->geocodedAddresses[0])->toBe(['forward', '1 Depot Road'])
        ->and($controller->uuidLookups[0])->toBe([
            ['contacts', 'vendors'],
            ['public_id'  => 'contact-public', 'company_uuid' => 'company-uuid'],
            ['with_table' => true],
        ])
        ->and($controller->firstOrNewLookups[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'owner_uuid'   => 'owner-uuid',
            'name'         => 'GEOCODED DEPOT',
            'street1'      => '1 DEPOT ROAD',
        ])
        ->and($response['place']->filledPayloads[array_key_last($response['place']->filledPayloads)])->toMatchArray([
            'name'         => 'Geocoded Depot',
            'street1'      => '1 Depot Road',
            'company_uuid' => 'company-uuid',
            'owner_uuid'   => 'owner-uuid',
            'owner_type'   => 'Fleetbase\\FleetOps\\Models\\Contact',
        ])
        ->and($response['place']->savedForTest)->toBeTrue();
});

test('api place controller creates reverse geocoded places from coordinates', function () {
    session(['company' => 'company-uuid']);
    $controller                       = new FleetOpsApiPlaceControllerProbe();
    $controller->reverseGeocodedPlace = fleetopsApiPlace([
        'name'    => 'Reverse Depot',
        'street1' => '2 Depot Road',
    ]);
    $controller->place = fleetopsApiPlace();

    $response = $controller->create(fleetopsCreatePlaceRequest([
        'latitude'  => 1.31,
        'longitude' => 103.81,
    ]));

    expect($response['resource'])->toBe('place')
        ->and($controller->pointInputs)->toBe([['latitude' => 1.31, 'longitude' => 103.81]])
        ->and($controller->geocodedAddresses[0])->toBe(['reverse', 1.3, 103.8])
        ->and($response['place']->filledPayloads[array_key_last($response['place']->filledPayloads)])->toMatchArray([
            'name'         => 'Reverse Depot',
            'street1'      => '2 Depot Road',
            'company_uuid' => 'company-uuid',
        ]);
});

test('api place controller updates owner vendor and coordinates', function () {
    session(['company' => 'company-uuid']);
    $controller        = new FleetOpsApiPlaceControllerProbe();
    $controller->place = fleetopsApiPlace();

    $response = $controller->update('place-public', fleetopsUpdatePlaceRequest([
        'name'      => 'Updated Depot',
        'street1'   => '3 Depot Road',
        'latitude'  => 1.32,
        'longitude' => 103.82,
        'owner'     => ['customer_id' => 'customer-owner'],
        'vendor'    => 'vendor-public',
    ]));

    expect($response['resource'])->toBe('place')
        ->and($response['place']->lookupId)->toBe('place-public')
        ->and($controller->coordinateInputs)->toBe([['latitude' => 1.32, 'longitude' => 103.82]])
        ->and($response['place']->updatedPayloads[0])->toMatchArray([
            'name'        => 'Updated Depot',
            'street1'     => '3 Depot Road',
            'owner_uuid'  => 'owner-uuid',
            'owner_type'  => 'Fleetbase\\FleetOps\\Models\\Contact',
            'vendor_uuid' => 'vendor-uuid',
        ])
        ->and($response['place']->updatedPayloads[0]['location'])->toBeInstanceOf(Point::class)
        ->and($response['place']->flushedForTest)->toBeTrue();
});

test('api place controller queries searches finds and deletes places', function () {
    session(['company' => 'company-uuid']);
    $controller                = new FleetOpsApiPlaceControllerProbe();
    $controller->place         = fleetopsApiPlace();
    $controller->queryResults  = [['uuid' => 'place-a'], ['uuid' => 'place-b']];
    $controller->searchResults = [['uuid' => 'search-a']];

    $query   = $controller->query(new Request(['vendor' => 'vendor-public']));
    $search  = $controller->search(new Request(['query' => 'Depot', 'limit' => 5, 'geo' => true]));
    $found   = $controller->find('place-public', new Request());
    $deleted = $controller->delete('place-public', new Request());

    expect($query)->toBe([
        'collection' => 'place',
        'items'      => [
            'items'       => [['uuid' => 'place-a'], ['uuid' => 'place-b']],
            'query_calls' => [['whereHas', 'vendor', [['where', 'public_id', 'vendor-public']]]],
        ],
    ])
        ->and($search)->toBe(['collection' => 'place', 'items' => [['uuid' => 'search-a']]])
        ->and($controller->searchCalls[0][1])->toBe('depot')
        ->and($controller->searchCalls[0][2])->toMatchArray(['limit' => 5, 'geo' => true, 'no_query_order' => 'name_desc'])
        ->and($found)->toBe(['resource' => 'place', 'place' => $controller->place])
        ->and($deleted)->toBe(['resource' => 'deleted-place', 'place' => $controller->place])
        ->and($controller->place->deletedForTest)->toBeTrue();
});

test('api place controller returns not found errors for missing places', function (string $method) {
    $controller                = new FleetOpsApiPlaceControllerProbe();
    $controller->placeNotFound = true;

    $response = $method === 'update'
        ? $controller->update('missing-place', fleetopsUpdatePlaceRequest([]))
        : $controller->{$method}('missing-place', new Request());

    expect($response)->toBe(['apiError' => 'Place resource not found.', 'status' => 400]);
})->with(['update', 'find', 'delete']);
