<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\EntityController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\FuelReportController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\IssueController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceAreaController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\TrackingStatusController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ZoneController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;

/**
 * A relationship sent as `""` or `null` must never be a 5xx.
 *
 * `ConvertEmptyStringsToNull` is global middleware, so `"driver": ""` reaches
 * the controller as null and `$request->has('driver')` is still true — `has()`
 * means the key is present, not that it holds anything. Every public
 * relationship input therefore arrives as null sooner or later, usually from a
 * form that serialises an unselected dropdown as an empty string.
 *
 * That was fine for years because the lookups were plain queries:
 * `where('public_id', null)` matches nothing and the caller's `if ($driver)`
 * skips. It broke when a coverage pass extracted one of those queries into
 * `findDriverByPublicId(string $publicId)` — the *return* type was made
 * nullable, the parameter was not — turning "no driver" into a TypeError on
 * `POST /v1/orders`.
 *
 * The static test below is the one that would have caught it: it holds the
 * property rather than the instance, so the next extraction cannot reintroduce
 * the class.
 */
function fleetopsNullRelationshipBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    app()->instance('db.schema', $connection->getSchemaBuilder());
    DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    foreach (['drivers', 'users', 'payloads', 'orders', 'service_areas', 'entities', 'waypoints', 'places'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach (['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'tracking_number_uuid', 'payload_uuid', 'place_uuid', 'name', '_key'] as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-uuid']);
    $connection->table('users')->insert(['uuid' => 'user-uuid', 'company_uuid' => 'company-uuid']);
    $connection->table('drivers')->insert(['uuid' => 'driver-uuid', 'public_id' => 'driver_real01', 'company_uuid' => 'company-uuid', 'user_uuid' => 'user-uuid']);
    $connection->table('payloads')->insert(['uuid' => 'payload-uuid', 'public_id' => 'payload_real01', 'company_uuid' => 'company-uuid']);
    $connection->table('orders')->insert(['uuid' => 'order-uuid', 'public_id' => 'order_real01', 'company_uuid' => 'company-uuid', 'tracking_number_uuid' => 'tracking-uuid']);
    $connection->table('service_areas')->insert(['uuid' => 'sa-uuid', 'public_id' => 'service_area_r1', 'company_uuid' => 'company-uuid']);

    return $connection;
}

function fleetopsInvokeSeam(object $controller, string $method, ...$arguments)
{
    $reflection = new ReflectionMethod($controller, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$arguments);
}

test('every request-fed lookup seam answers rather than raising when handed nothing', function () {
    fleetopsNullRelationshipBoot();

    // Each of these is reached from `$request->input('<relationship>')`. A null
    // means the caller sent an empty relationship, which is a question with an
    // answer — "there is no such record" — not a programming error.
    expect(fleetopsInvokeSeam(new OrderController(), 'findDriverByPublicId', null))->toBeNull()
        ->and(fleetopsInvokeSeam(new EntityController(), 'findPayloadByPublicId', null))->toBeNull()
        ->and(fleetopsInvokeSeam(new TrackingStatusController(), 'getOrderTrackingNumberUuid', null))->toBeNull()
        ->and(fleetopsInvokeSeam(new ServiceAreaController(), 'serviceAreaUuid', null, ['public_id' => null, 'company_uuid' => 'company-uuid']))->toBeNull()
        ->and(fleetopsInvokeSeam(new ZoneController(), 'serviceAreaUuid', null, ['public_id' => null, 'company_uuid' => 'company-uuid']))->toBeNull();

    // Issue and FuelReport reach their seam only from `create`, where `driver`
    // is `required` — validation answers 422 long before a null could arrive.
    // Widening them is defence-in-depth for a future call site, so the claim
    // here is only the one that matters: whatever they answer, it is not a
    // TypeError. A TypeError would escape this block and fail the test.
    foreach ([new IssueController(), new FuelReportController()] as $controller) {
        try {
            fleetopsInvokeSeam($controller, 'findDriverRecord', null);
        } catch (ModelNotFoundException) {
            // "No such driver" — the call sites already translate this into a 404.
        }
    }
});

test('a real identifier still resolves through the widened seams', function () {
    fleetopsNullRelationshipBoot();

    // Widening the parameter must not have widened the lookup: a present
    // identifier resolves exactly as before, and one that does not exist still
    // resolves to nothing.
    expect(fleetopsInvokeSeam(new OrderController(), 'findDriverByPublicId', 'driver_real01')->uuid)->toBe('driver-uuid')
        ->and(fleetopsInvokeSeam(new OrderController(), 'findDriverByPublicId', 'driver_missing'))->toBeNull()
        ->and(fleetopsInvokeSeam(new EntityController(), 'findPayloadByPublicId', 'payload_real01')->uuid)->toBe('payload-uuid')
        ->and(fleetopsInvokeSeam(new TrackingStatusController(), 'getOrderTrackingNumberUuid', 'order_real01'))->toBe('tracking-uuid')
        ->and(fleetopsInvokeSeam(new ServiceAreaController(), 'serviceAreaUuid', 'service_area_r1', ['public_id' => 'service_area_r1', 'company_uuid' => 'company-uuid']))->toBe('sa-uuid');
});

test('no public api lookup seam fed from the request refuses a null identifier', function () {
    // The systemic guard. Rather than pinning the eight seams that exist today,
    // it derives them: every call of the form `$this->someSeam($request->input(...))`
    // in the public v1 controllers, checked for a nullable first parameter.
    //
    // An extraction that types a new seam `string` fails here, at the commit
    // that introduces it, instead of in production on an empty dropdown.
    // Exempt only where the *call site* already excludes null, and say which
    // guard does it — an exemption without a named guard is how this test would
    // rot into a rubber stamp.
    $exempt = [
        // Not an identifier lookup — it geocodes a free-text address, and a null
        // address has no meaning to geocode. Its call sites guard with
        // `$request->isString(...)`, which a null can never satisfy.
        'PlaceController::createPlaceFromGeocodingLookup',
        // Both call sites are behind `$request->isArray('payload')`. A payload
        // sent as `""` fails that guard and is skipped, never unpacked.
        'OrderController::payloadShapeFromArray',
    ];

    $offenders = [];
    $inspected = [];

    foreach (glob(dirname(__DIR__, 4) . '/src/Http/Controllers/Api/v1/*.php') as $path) {
        $source     = file_get_contents($path);
        $controller = basename($path, '.php');

        preg_match_all('/\$this->([A-Za-z_]+)\(\s*\$request->(?:input|or)\(/', $source, $matches);

        foreach (array_unique($matches[1]) as $seam) {
            if (in_array($controller . '::' . $seam, $exempt, true)) {
                continue;
            }

            if (!preg_match('/(?:protected|public|private) function ' . preg_quote($seam, '/') . '\(([^),]*)/', $source, $signature)) {
                continue;
            }

            $firstParameter = trim($signature[1]);
            $inspected[]    = $controller . '::' . $seam;

            // `mixed`, an untyped parameter, and anything explicitly nullable all
            // accept null already; only a narrow non-nullable type is a problem.
            $accepts = $firstParameter === ''
                || str_starts_with($firstParameter, '?')
                || str_starts_with($firstParameter, '$')
                || str_contains($firstParameter, 'null')
                || str_starts_with($firstParameter, 'mixed');

            if (!$accepts) {
                $offenders[] = $controller . '::' . $seam . '(' . $firstParameter . ')';
            }
        }
    }

    // A guard that scans nothing passes for the wrong reason, so make the scan
    // prove itself: the seam this bug was reported against has to be among what
    // it looked at. If the glob or the pattern ever stops matching, this fails
    // instead of going quietly green.
    expect($inspected)->toContain('OrderController::findDriverByPublicId')
        ->and($offenders)->toBe([], 'these seams are handed a request value and would raise a TypeError on an empty relationship: ' . implode(', ', $offenders));
});
