<?php

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Geocoder\Provider\GoogleMaps\Model\GoogleAddress;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;

class FleetOpsPlaceMixedQueryFake
{
    public array $calls = [];

    public function __construct(
        public ?Place $result = null,
        public bool $exists = false,
    ) {
    }

    public function where(...$arguments): static
    {
        $this->calls[] = ['where', $arguments];

        if (isset($arguments[0]) && is_callable($arguments[0])) {
            $arguments[0]($this);
        }

        return $this;
    }

    public function orWhere(...$arguments): static
    {
        $this->calls[] = ['orWhere', $arguments];

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->calls[] = ['whereRaw', $sql, $bindings];

        return $this;
    }

    public function when(bool $condition, callable $callback): static
    {
        $this->calls[] = ['when', $condition];

        if ($condition) {
            $callback($this);
        }

        return $this;
    }

    public function first(): ?Place
    {
        return $this->result;
    }

    public function exists(): bool
    {
        return $this->exists;
    }
}

class FleetOpsPlaceGoogleAddressProbe extends Place
{
    public static ?Place $duplicate = null;
    public static array $inserted   = [];
    public bool $saved              = false;

    public static function resetProbe(): void
    {
        static::$duplicate = null;
        static::$inserted  = [];
    }

    public static function findExistingSharedPlace(array $place): ?Place
    {
        return static::$duplicate;
    }

    public static function insertGetUuid($values = [])
    {
        static::$inserted[] = $values;

        return 'inserted-place-uuid';
    }

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }

    public function toArray()
    {
        return $this->getAttributes();
    }
}

class FleetOpsPlaceMixedProbe extends Place
{
    public static array $whereResults       = [];
    public static array $whereExists        = [];
    public static array $createdValues      = [];
    public static array $geocodingLookups   = [];
    public static array $coordinateCreates  = [];
    public static array $coordinateInserts  = [];
    public static array $googleCreates      = [];
    public static array $googleInserts      = [];
    public static array $insertedValues     = [];
    public static ?Place $searchResult      = null;
    public static ?Place $sharedPlace       = null;
    public static ?array $sharedHydrateArgs = null;

    public static function resetProbe(): void
    {
        static::$whereResults      = [];
        static::$whereExists       = [];
        static::$createdValues     = [];
        static::$geocodingLookups  = [];
        static::$coordinateCreates = [];
        static::$coordinateInserts = [];
        static::$googleCreates     = [];
        static::$googleInserts     = [];
        static::$insertedValues    = [];
        static::$searchResult      = null;
        static::$sharedPlace       = null;
        static::$sharedHydrateArgs = null;
    }

    public static function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $lookup = $value ?? $operator;
        $key    = $column . ':' . $lookup;

        return new FleetOpsPlaceMixedQueryFake(
            static::$whereResults[$key] ?? null,
            static::$whereExists[$key] ?? false
        );
    }

    public static function query()
    {
        return new FleetOpsPlaceMixedQueryFake(static::$searchResult);
    }

    public static function createFromGeocodingLookup(string $address, $saveInstance = false): ?Place
    {
        static::$geocodingLookups[] = [$address, $saveInstance];

        return new static(['street1' => $address]);
    }

    public static function getValuesFromGeocodingLookup(string $address): array
    {
        static::$geocodingLookups[] = [$address, false];

        return [
            'street1'  => $address,
            'city'     => 'Geocoded City',
            'country'  => 'SG',
            'location' => new Point(1.3, 103.8),
        ];
    }

    public static function createFromCoordinates($coordinates, array $attributes = [], $saveInstance = false): ?Place
    {
        static::$coordinateCreates[] = [$coordinates, $attributes, $saveInstance];

        return new static(array_merge($attributes, ['street1' => 'coordinates']));
    }

    public static function createFromGoogleAddress(GoogleAddress $address, bool $saveInstance = false): ?Place
    {
        static::$googleCreates[] = [$address, $saveInstance];

        return new static(['street1' => 'google-address']);
    }

    public static function create(array $attributes = [])
    {
        static::$createdValues[] = $attributes;

        return new static($attributes);
    }

    public static function findExistingSharedPlace(array $place): ?Place
    {
        return static::$sharedPlace;
    }

    public static function hydrateSharedPlace(Place $existingPlace, array $values): Place
    {
        static::$sharedHydrateArgs = [$existingPlace, $values];

        return $existingPlace;
    }

    public static function insertFromCoordinates($coordinates, array $attributes = [])
    {
        static::$coordinateInserts[] = [$coordinates, $attributes];

        return 'coordinate-place-uuid';
    }

    public static function insertFromGeocodingLookup(string $address)
    {
        static::$geocodingLookups[] = [$address, false];

        return 'geocoded-place-uuid';
    }

    public static function insertFromGoogleAddress(GoogleAddress $address)
    {
        static::$googleInserts[] = $address;

        return 'google-place-uuid';
    }

    public static function insertGetUuid($values = [])
    {
        static::$insertedValues[] = $values;

        return 'inserted-mixed-place-uuid';
    }
}

function fleetopsPlaceUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsGoogleAddress(array $overrides = []): GoogleAddress
{
    $defaults = [
        'latitude'      => 1.3521,
        'longitude'     => 103.8198,
        'streetNumber'  => '42',
        'streetName'    => 'Depot Road',
        'postalCode'    => '018956',
        'locality'      => 'Singapore',
        'subLocality'   => 'Central',
        'country'       => 'Singapore',
        'countryCode'   => 'SG',
    ];

    return GoogleAddress::createFromArray(array_merge($defaults, $overrides))
        ->withStreetAddress(data_get($overrides, 'streetAddress', '42 Depot Road'))
        ->withFormattedAddress(data_get($overrides, 'formattedAddress', '42 Depot Road, Singapore, SG'))
        ->withNeighborhood(data_get($overrides, 'neighborhood', 'Downtown'));
}

test('place relationship contracts resolve expected relation types', function () {
    fleetopsPlaceUseRelationConnection();

    $place = new Place();

    expect($place->owner())->toBeInstanceOf(MorphTo::class)
        ->and($place->company())->toBeInstanceOf(BelongsTo::class);
});

test('place fills empty fields from google address and keeps existing values', function () {
    $place = new Place([
        'street1' => 'Existing Street',
        'city'    => null,
    ]);

    $address = fleetopsGoogleAddress();

    expect($place->fillWithGoogleAddress($address))->toBe($place)
        ->and($place->street1)->toBe('Existing Street')
        ->and($place->postal_code)->toBe('018956')
        ->and($place->neighborhood)->toBe('Downtown')
        ->and($place->city)->toBe('Singapore')
        ->and($place->building)->toBe('42')
        ->and($place->country)->toBe('SG')
        ->and($place->location)->toBeInstanceOf(Point::class)
        ->and($place->location->getLat())->toBe(1.3521)
        ->and($place->location->getLng())->toBe(103.8198);
});

test('place falls back to formatted google address when street parts are empty', function () {
    $place = new Place();

    $address = fleetopsGoogleAddress([
        'streetAddress'    => null,
        'streetNumber'     => null,
        'streetName'       => null,
        'formattedAddress' => 'Warehouse A, Port Road, Singapore',
    ]);

    $place->fillWithGoogleAddress($address);

    expect($place->street1)->toBe('Warehouse A,  Port Road');
});

test('place converts google addresses to arrays and handles null addresses', function () {
    $empty = Place::getGoogleAddressArray(null);
    $full  = Place::getGoogleAddressArray(fleetopsGoogleAddress());

    expect($empty['location'])->toBeInstanceOf(Point::class)
        ->and($empty['location']->getLat())->toBe(0.0)
        ->and($full)->toMatchArray([
            'name'         => '42 Depot Road',
            'street1'      => '42 Depot Road',
            'postal_code'  => '018956',
            'neighborhood' => 'Downtown',
            'city'         => 'Singapore',
            'building'     => '42',
            'country'      => 'SG',
        ])
        ->and($full['location']->getLat())->toBe(1.3521)
        ->and($full['location']->getLng())->toBe(103.8198);
});

test('place google address creation returns duplicates saves new places and inserts address values', function () {
    FleetOpsPlaceGoogleAddressProbe::resetProbe();

    $duplicate                                  = new FleetOpsPlaceGoogleAddressProbe(['name' => 'Existing']);
    FleetOpsPlaceGoogleAddressProbe::$duplicate = $duplicate;

    expect(FleetOpsPlaceGoogleAddressProbe::createFromGoogleAddress(fleetopsGoogleAddress(), true))->toBe($duplicate);

    FleetOpsPlaceGoogleAddressProbe::$duplicate = null;

    $created = FleetOpsPlaceGoogleAddressProbe::createFromGoogleAddress(fleetopsGoogleAddress(), true);

    expect($created)->toBeInstanceOf(FleetOpsPlaceGoogleAddressProbe::class)
        ->and($created->saved)->toBeTrue()
        ->and($created->street1)->toBe('42 Depot Road');

    expect(FleetOpsPlaceGoogleAddressProbe::insertFromGoogleAddress(fleetopsGoogleAddress()))->toBe('inserted-place-uuid')
        ->and(FleetOpsPlaceGoogleAddressProbe::$inserted[0])->toMatchArray([
            'street1' => '42 Depot Road',
            'country' => 'SG',
        ]);
});

test('place creates from mixed strings coordinates arrays and google addresses', function () {
    FleetOpsPlaceMixedProbe::resetProbe();

    $publicPlace                                                      = new FleetOpsPlaceMixedProbe(['uuid' => 'public-place-uuid']);
    FleetOpsPlaceMixedProbe::$whereResults['public_id:place_public1'] = $publicPlace;

    expect(FleetOpsPlaceMixedProbe::createFromMixed('place_public1'))->toBe($publicPlace);

    $uuidPlace                                                                          = new FleetOpsPlaceMixedProbe(['uuid' => '11111111-1111-4111-8111-111111111111']);
    FleetOpsPlaceMixedProbe::$whereResults['uuid:11111111-1111-4111-8111-111111111111'] = $uuidPlace;

    expect(FleetOpsPlaceMixedProbe::createFromMixed('11111111-1111-4111-8111-111111111111'))->toBe($uuidPlace);

    $searchPlace                           = new FleetOpsPlaceMixedProbe(['name' => 'Depot']);
    FleetOpsPlaceMixedProbe::$searchResult = $searchPlace;

    expect(FleetOpsPlaceMixedProbe::createFromMixed('Depot'))->toBe($searchPlace);

    FleetOpsPlaceMixedProbe::$searchResult = null;

    expect(FleetOpsPlaceMixedProbe::createFromMixed('New Depot', [], false))->toBeInstanceOf(FleetOpsPlaceMixedProbe::class)
        ->and(FleetOpsPlaceMixedProbe::$geocodingLookups)->toContain(['New Depot', false]);

    $coordinatePlace = FleetOpsPlaceMixedProbe::createFromMixed([1.3, 103.8], ['name' => 'Coordinate Depot'], false);

    expect($coordinatePlace)->toBeInstanceOf(FleetOpsPlaceMixedProbe::class)
        ->and($coordinatePlace->street1)->toBe('coordinates')
        ->and(FleetOpsPlaceMixedProbe::$coordinateCreates[0])->toBe([[1.3, 103.8], ['name' => 'Coordinate Depot'], false]);

    $googleAddress = fleetopsGoogleAddress();

    expect(FleetOpsPlaceMixedProbe::createFromMixed($googleAddress, [], true))->toBeInstanceOf(FleetOpsPlaceMixedProbe::class)
        ->and(FleetOpsPlaceMixedProbe::$googleCreates[0])->toBe([$googleAddress, true])
        ->and(FleetOpsPlaceMixedProbe::createFromMixed(12345))->toBeNull();
});

test('place creates from structured arrays using existing lookup geocoding and shared hydration', function () {
    FleetOpsPlaceMixedProbe::resetProbe();

    $uuidPlace                                                                          = new FleetOpsPlaceMixedProbe(['uuid' => '22222222-2222-4222-8222-222222222222']);
    FleetOpsPlaceMixedProbe::$whereResults['uuid:22222222-2222-4222-8222-222222222222'] = $uuidPlace;

    expect(FleetOpsPlaceMixedProbe::createFromMixed(['uuid' => '22222222-2222-4222-8222-222222222222']))->toBe($uuidPlace);

    $publicPlace                                                      = new FleetOpsPlaceMixedProbe(['public_id' => 'place_exists1']);
    FleetOpsPlaceMixedProbe::$whereResults['public_id:place_exists1'] = $publicPlace;

    expect(FleetOpsPlaceMixedProbe::createFromMixed(['public_id' => 'place_exists1']))->toBe($publicPlace);

    $shared                               = new FleetOpsPlaceMixedProbe(['uuid' => 'shared-place-uuid']);
    FleetOpsPlaceMixedProbe::$sharedPlace = $shared;

    expect(FleetOpsPlaceMixedProbe::createFromMixed([
        'street1'     => '  10 Port Road  ',
        'city'        => ' Singapore ',
        'country'     => ' SG ',
        'postal_code' => '',
    ]))->toBe($shared)
        ->and(FleetOpsPlaceMixedProbe::$sharedHydrateArgs[0])->toBe($shared)
        ->and(FleetOpsPlaceMixedProbe::$sharedHydrateArgs[1])->toMatchArray([
            'street1' => '10 Port Road',
            'city'    => 'Singapore',
            'country' => 'SG',
        ]);

    FleetOpsPlaceMixedProbe::$sharedPlace = null;

    $created = FleetOpsPlaceMixedProbe::createFromMixed([
        'address' => '20 Keppel Road',
        'phone'   => '  +65 5555 0000  ',
    ]);

    expect($created)->toBeInstanceOf(FleetOpsPlaceMixedProbe::class)
        ->and(FleetOpsPlaceMixedProbe::$geocodingLookups)->toContain(['20 Keppel Road', false])
        ->and(FleetOpsPlaceMixedProbe::$createdValues[0]['location'])->toBeInstanceOf(Point::class)
        ->and(FleetOpsPlaceMixedProbe::$createdValues[0]['phone'])->toBe('+65 5555 0000');
});

test('place inserts from mixed values and returns existing identifiers when possible', function () {
    FleetOpsPlaceMixedProbe::resetProbe();

    expect(FleetOpsPlaceMixedProbe::insertFromMixed([1.3, 103.8]))->toBe('coordinate-place-uuid')
        ->and(FleetOpsPlaceMixedProbe::$coordinateInserts[0])->toBe([[1.3, 103.8], []])
        ->and(FleetOpsPlaceMixedProbe::insertFromMixed('Warehouse address'))->toBe('geocoded-place-uuid')
        ->and(FleetOpsPlaceMixedProbe::$geocodingLookups)->toContain(['Warehouse address', false]);

    $publicPlace = new FleetOpsPlaceMixedProbe();
    $publicPlace->setRawAttributes(['uuid' => 'public-existing-uuid'], true);
    FleetOpsPlaceMixedProbe::$whereExists['public_id:place_insert1']  = true;
    FleetOpsPlaceMixedProbe::$whereResults['public_id:place_insert1'] = $publicPlace;

    expect(FleetOpsPlaceMixedProbe::insertFromMixed(['public_id' => 'place_insert1']))->toBe('public-existing-uuid');

    $uuid                                                  = '33333333-3333-4333-8333-333333333333';
    FleetOpsPlaceMixedProbe::$whereExists['uuid:' . $uuid] = true;

    expect(FleetOpsPlaceMixedProbe::insertFromMixed(['uuid' => $uuid]))->toBe($uuid);

    $shared = new FleetOpsPlaceMixedProbe();
    $shared->setRawAttributes(['uuid' => 'shared-existing-uuid'], true);
    FleetOpsPlaceMixedProbe::$sharedPlace = $shared;

    expect(FleetOpsPlaceMixedProbe::insertFromMixed(['street1' => '10 Port Road', 'city' => 'Singapore', 'country' => 'SG']))->toBe('shared-existing-uuid');

    FleetOpsPlaceMixedProbe::$sharedPlace = null;

    expect(FleetOpsPlaceMixedProbe::insertFromMixed((object) ['street1' => '20 Port Road']))->toBe('inserted-mixed-place-uuid')
        ->and(FleetOpsPlaceMixedProbe::$insertedValues[0])->toBe(['street1' => '20 Port Road']);
});
