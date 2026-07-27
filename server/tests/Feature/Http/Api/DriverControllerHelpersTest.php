<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Driver as DriverResource;
use Fleetbase\FleetOps\Jobs\SimulateDrivingRoute;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\TestSupport\DispatchRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exercises the real bodies of the API DriverController's protected helper
 * methods and the self-contained route-simulation methods. The existing
 * ApiDriverControllerContractsTest overrides these helpers on its probe, which
 * leaves their real one-line implementations uncovered; here we invoke the
 * genuine parent implementations through reflection so the underlying facade
 * and resource calls execute.
 */
class FleetOpsApiDriverHelpersProbe extends DriverController
{
    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(DriverController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsApiDriverHelpersDriverFake extends Driver
{
    protected $guarded = [];
    public $exists     = true;

    public function __construct(array $attributes = [])
    {
        parent::__construct();
        $this->setRawAttributes($attributes, true);
    }
}

class FleetOpsApiDriverHelpersPlaceFake extends Fleetbase\FleetOps\Models\Place
{
    protected $guarded    = [];
    public $exists        = true;
    public ?Point $point  = null;

    public static function at(Point $point): self
    {
        $place        = new self();
        $place->point = $point;
        $place->setRawAttributes(['uuid' => 'place-uuid'], true);

        return $place;
    }

    public function getAttribute($key)
    {
        if ($key === 'location') {
            return $this->point;
        }

        return parent::getAttribute($key);
    }
}

class FleetOpsApiDriverHelpersPayloadFake extends Payload
{
    protected $guarded                               = [];
    public $exists                                   = true;
    public ?Fleetbase\FleetOps\Models\Place $pickup  = null;
    public ?Fleetbase\FleetOps\Models\Place $dropoff = null;

    public function __construct()
    {
        parent::__construct();
        $this->setRawAttributes(['uuid' => 'payload-uuid'], true);
    }

    public function getPickupOrFirstWaypoint(): ?Fleetbase\FleetOps\Models\Place
    {
        return $this->pickup;
    }

    public function getDropoffOrLastWaypoint(): ?Fleetbase\FleetOps\Models\Place
    {
        return $this->dropoff;
    }
}

class FleetOpsApiDriverHelpersOrderFake extends Order
{
    protected $guarded = [];
    public $exists     = true;

    public function __construct(array $attributes = [])
    {
        parent::__construct();
        $this->setRawAttributes(array_merge(['uuid' => 'order-uuid'], $attributes), true);
    }
}

function fleetopsApiDriverHelpersProbe(): FleetOpsApiDriverHelpersProbe
{
    return new FleetOpsApiDriverHelpersProbe();
}

function fleetopsApiDriverHelpersSeedRoute(Point $start, Point $end, array $route): void
{
    $key = 'getRoute:' . md5($start . $end . serialize([]));
    Illuminate\Support\Facades\Cache::put($key, $route, 3600);
}

/*
|--------------------------------------------------------------------------
| Protected helper methods
|--------------------------------------------------------------------------
*/

test('session company helper reads the company from the session store', function () {
    session(['company' => 'company-abc']);

    expect(fleetopsApiDriverHelpersProbe()->callProtected('sessionCompany'))->toBe('company-abc');
});

test('point from coordinates helper builds a spatial point', function () {
    $point = fleetopsApiDriverHelpersProbe()->callProtected('pointFromCoordinates', [[
        'latitude'  => 1.3521,
        'longitude' => 103.8198,
    ]]);

    expect($point)->toBeInstanceOf(Point::class)
        ->and($point->getLat())->toBe(1.3521)
        ->and($point->getLng())->toBe(103.8198);
});

test('json response helper returns a json response with the supplied status', function () {
    $response = fleetopsApiDriverHelpersProbe()->callProtected('jsonResponse', [['ok' => true], 201]);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(201)
        ->and($response->getData(true))->toBe(['ok' => true]);
});

test('driver resource helpers wrap drivers in their api resources', function () {
    $driver = new FleetOpsApiDriverHelpersDriverFake(['uuid' => 'driver-uuid', 'public_id' => 'driver_test']);
    $probe  = fleetopsApiDriverHelpersProbe();

    expect($probe->callProtected('driverResource', [$driver]))->toBeInstanceOf(DriverResource::class)
        ->and($probe->callProtected('deletedDriverResource', [$driver]))->toBeInstanceOf(DeletedResource::class)
        ->and($probe->callProtected('driverResourceCollection', [[$driver]]))
        ->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class);
});

test('apply user info helper delegates to the user model helper', function () {
    $request = Request::create('/fleet-ops/drivers', 'POST');

    $result = fleetopsApiDriverHelpersProbe()->callProtected('applyUserInfoFromRequest', [$request, ['name' => 'Ada']]);

    expect($result)->toBeArray()
        ->and($result['name'])->toBe('Ada');
});

/*
|--------------------------------------------------------------------------
| Route simulation
|--------------------------------------------------------------------------
*/

test('simulate driving for route returns the osrm payload when no route is found', function () {
    DispatchRecorder::$dispatched = [];
    $driver                       = new FleetOpsApiDriverHelpersDriverFake(['uuid' => 'driver-uuid']);
    $start                        = new Point(1.0, 2.0);
    $end                          = new Point(3.0, 4.0);
    fleetopsApiDriverHelpersSeedRoute($start, $end, ['code' => 'NoRoute']);

    $response = fleetopsApiDriverHelpersProbe()->simulateDrivingForRoute($driver, $start, $end);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true))->toBe(['code' => 'NoRoute'])
        ->and(DispatchRecorder::$dispatched)->toBe([]);
});

test('simulate driving for route dispatches the simulation job on a successful route', function () {
    DispatchRecorder::$dispatched = [];
    $driver                       = new FleetOpsApiDriverHelpersDriverFake(['uuid' => 'driver-uuid']);
    $start                        = new Point(1.0, 2.0);
    $end                          = new Point(3.0, 4.0);
    fleetopsApiDriverHelpersSeedRoute($start, $end, ['code' => 'Ok', 'routes' => [['geometry' => 'ojfGx_zsAvBcH']]]);

    $response = fleetopsApiDriverHelpersProbe()->simulateDrivingForRoute($driver, $start, $end);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true)['code'])->toBe('Ok')
        ->and(DispatchRecorder::$dispatched)->toHaveCount(1)
        ->and(DispatchRecorder::$dispatched[0]['job'])->toBe(SimulateDrivingRoute::class);
});

test('simulate driving for order computes headings and dispatches the simulation job', function () {
    DispatchRecorder::$dispatched = [];
    $driver                       = new FleetOpsApiDriverHelpersDriverFake(['uuid' => 'driver-uuid']);
    $payload                      = new FleetOpsApiDriverHelpersPayloadFake();
    $payload->pickup              = FleetOpsApiDriverHelpersPlaceFake::at(new Point(1.0, 2.0));
    $payload->dropoff             = FleetOpsApiDriverHelpersPlaceFake::at(new Point(3.0, 4.0));
    $order                        = new FleetOpsApiDriverHelpersOrderFake();
    $order->setRelation('payload', $payload);
    $start = Fleetbase\FleetOps\Support\Utils::getPointFromMixed($payload->pickup);
    $end   = Fleetbase\FleetOps\Support\Utils::getPointFromMixed($payload->dropoff);
    fleetopsApiDriverHelpersSeedRoute($start, $end, ['code' => 'Ok', 'routes' => [['geometry' => 'ojfGx_zsAvBcH']]]);

    $response = fleetopsApiDriverHelpersProbe()->simulateDrivingForOrder($driver, $order);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true)['code'])->toBe('Ok')
        ->and(DispatchRecorder::$dispatched)->toHaveCount(1)
        ->and(DispatchRecorder::$dispatched[0]['job'])->toBe(SimulateDrivingRoute::class);
});

test('simulate driving for order returns the osrm payload when no route is found', function () {
    DispatchRecorder::$dispatched = [];
    $driver                       = new FleetOpsApiDriverHelpersDriverFake(['uuid' => 'driver-uuid']);
    $payload                      = new FleetOpsApiDriverHelpersPayloadFake();
    $payload->pickup              = FleetOpsApiDriverHelpersPlaceFake::at(new Point(5.0, 6.0));
    $payload->dropoff             = FleetOpsApiDriverHelpersPlaceFake::at(new Point(7.0, 8.0));
    $order                        = new FleetOpsApiDriverHelpersOrderFake();
    $order->setRelation('payload', $payload);
    $start = Fleetbase\FleetOps\Support\Utils::getPointFromMixed($payload->pickup);
    $end   = Fleetbase\FleetOps\Support\Utils::getPointFromMixed($payload->dropoff);
    fleetopsApiDriverHelpersSeedRoute($start, $end, ['code' => 'NoRoute']);

    $response = fleetopsApiDriverHelpersProbe()->simulateDrivingForOrder($driver, $order);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true))->toBe(['code' => 'NoRoute'])
        ->and(DispatchRecorder::$dispatched)->toBe([]);
});
