<?php

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Geocoder\Provider\GoogleMaps\Model\GoogleAddress;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;

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
