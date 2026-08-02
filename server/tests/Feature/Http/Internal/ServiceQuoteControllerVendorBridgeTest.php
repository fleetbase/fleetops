<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ServiceQuoteController;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the internal ServiceQuoteController integrated-vendor branches
 * through the container-injectable vendor bridge: payload-backed vendor
 * quotes (single and collection), bridge failures returning 400 errors,
 * and preliminary vendor quotes persisting the query metadata.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!class_exists('PhpOption\\Option', false)) {
    eval('namespace PhpOption; abstract class Option { public static function fromValue($value, $noneValue = null) { return $value === $noneValue ? None::create() : new Some($value); } abstract public function getOrCall($callable); abstract public function map($callable); abstract public function filter($callable); } class Some extends Option { public function __construct(private mixed $value) {} public function getOrCall($callable) { return $this->value; } public function map($callable) { return new Some($callable($this->value)); } public function filter($callable) { return $callable($this->value) ? $this : None::create(); } } class None extends Option { public static function create() { return new self(); } public function getOrCall($callable) { return $callable(); } public function map($callable) { return $this; } public function filter($callable) { return $this; } }');
}

if (!class_exists('Dotenv\\Repository\\RepositoryBuilder', false)) {
    eval('namespace Dotenv\\Repository; class RepositoryBuilder { public static function createWithDefaultAdapters() { return new self(); } public function addAdapter($adapter) { return $this; } public function immutable() { return $this; } public function make() { return new class { public function get($key) { $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key); return $value === false ? null : $value; } public function has($key) { return $this->get($key) !== null; } }; } }');
}

class FleetOpsInternalQuoteBridgeQuoteFake extends ServiceQuote
{
    protected $guarded = [];
    public $exists     = true;
}

function fleetopsInternalQuoteVendorBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    app()->instance('db.schema', $schema);
    $tables = [
        'service_quotes'     => ['uuid', 'public_id', 'request_id', 'company_uuid', 'service_rate_uuid', 'payload_uuid', 'integrated_vendor_uuid', 'amount', 'currency', 'meta', 'expired_at', '_key'],
        'integrated_vendors' => ['uuid', 'public_id', 'company_uuid', 'provider', 'webhook_url', 'host', 'namespace', 'credentials', 'options', 'sandbox', 'status', '_key'],
        'payloads'           => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type', 'meta', '_key'],
        'places'             => ['uuid', 'public_id', 'company_uuid', 'name', 'location', '_key'],
        'waypoints'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order', 'type', 'status', '_key'],
        'entities'           => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'name', 'type', 'status', 'meta', '_key'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);
    app()->instance('redis', new class {
        public function __call($method, $arguments)
        {
            return null;
        }

        public function connection($name = null)
        {
            return $this;
        }
    });
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());
    $connection->table('integrated_vendors')->insert(['uuid' => 'iv-int-quote-1', 'public_id' => 'integrated_vendor_intquote1', 'company_uuid' => 'company-1', 'provider' => 'lalamove', 'credentials' => json_encode([]), 'options' => json_encode([]), 'sandbox' => '1']);
    $connection->table('payloads')->insert(['uuid' => 'payload-int-quote-1', 'public_id' => 'payload_intquote1', 'company_uuid' => 'company-1']);
    $connection->table('places')->insert([
        ['uuid' => 'place-int-pickup', 'public_id' => 'place_intpickup1', 'company_uuid' => 'company-1', 'name' => 'Pickup'],
        ['uuid' => 'place-int-dropoff', 'public_id' => 'place_intdropoff1', 'company_uuid' => 'company-1', 'name' => 'Dropoff'],
    ]);
    $connection->table('service_quotes')->insert(['uuid' => 'quote-int-bridge-1', 'public_id' => 'quote_intbridge1', 'company_uuid' => 'company-1', 'amount' => '4200', 'currency' => 'SGD', 'meta' => json_encode([])]);

    $GLOBALS['fleetopsInternalQuoteBridgeMode'] = 'ok';
    app()->bind(Fleetbase\FleetOps\Integrations\Lalamove\Lalamove::class, function () {
        return new class {
            public function setIntegratedVendor($vendor)
            {
                return $this;
            }

            public function setRequestId($id)
            {
                return $this;
            }

            protected function makeQuote(): ServiceQuote
            {
                $quote = new FleetOpsInternalQuoteBridgeQuoteFake();
                $quote->setRawAttributes(['uuid' => 'quote-int-bridge-1', 'public_id' => 'quote_intbridge1', 'company_uuid' => 'company-1', 'amount' => '4200', 'currency' => 'SGD', 'meta' => json_encode([])], true);

                return $quote;
            }

            public function getQuoteFromPayload($payload, $serviceType = null, $scheduledAt = null, $isRouteOptimized = true)
            {
                if ($GLOBALS['fleetopsInternalQuoteBridgeMode'] === 'fail') {
                    throw new Exception('internal vendor quote unavailable');
                }

                return $this->makeQuote();
            }

            public function getQuoteFromPreliminaryPayload($waypoints, $entities, $serviceType = null, $scheduledAt = null, $isRouteOptimized = true)
            {
                if ($GLOBALS['fleetopsInternalQuoteBridgeMode'] === 'fail') {
                    throw new Exception('internal vendor preliminary quote unavailable');
                }

                return $this->makeQuote();
            }

            public function __call($method, $arguments)
            {
                return $this;
            }
        };
    });

    return $connection;
}

function fleetopsInternalQuoteVendorRequest(array $input): Request
{
    $request = Request::create('/int/v1/service-quotes', 'GET', $input);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

test('internal payload vendor quotes return single and collection responses', function () {
    fleetopsInternalQuoteVendorBoot();
    $controller = new ServiceQuoteController();

    $single = $controller->queryRecord(fleetopsInternalQuoteVendorRequest([
        'payload'     => 'payload_intquote1',
        'facilitator' => 'integrated_vendor_intquote1',
        'single'      => '1',
    ]));
    expect($single)->toBeInstanceOf(JsonResponse::class)
        ->and($single->getStatusCode())->toBe(200);

    $collection = $controller->queryRecord(fleetopsInternalQuoteVendorRequest([
        'payload'     => 'payload_intquote1',
        'facilitator' => 'integrated_vendor_intquote1',
    ]));
    expect($collection)->toBeInstanceOf(JsonResponse::class)
        ->and(json_encode($collection->getData(true)))->toContain('quote_intbridge1');
});

test('internal payload vendor bridge failures return 400 error responses', function () {
    fleetopsInternalQuoteVendorBoot();
    $GLOBALS['fleetopsInternalQuoteBridgeMode'] = 'fail';

    $response = (new ServiceQuoteController())->queryRecord(fleetopsInternalQuoteVendorRequest([
        'payload'     => 'payload_intquote1',
        'facilitator' => 'integrated_vendor_intquote1',
    ]));
    $GLOBALS['fleetopsInternalQuoteBridgeMode'] = 'ok';

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(400)
        ->and(json_encode($response->getData(true)))->toContain('internal vendor quote unavailable');
});

test('internal preliminary vendor quotes persist query metadata', function () {
    $connection = fleetopsInternalQuoteVendorBoot();
    $controller = new ServiceQuoteController();

    $single = $controller->queryRecord(fleetopsInternalQuoteVendorRequest([
        'pickup'      => 'place_intpickup1',
        'dropoff'     => 'place_intdropoff1',
        'facilitator' => 'integrated_vendor_intquote1',
        'single'      => '1',
    ]));
    expect($single)->toBeInstanceOf(JsonResponse::class)
        ->and((string) $connection->table('service_quotes')->where('uuid', 'quote-int-bridge-1')->value('meta'))->toContain('preliminary_query');

    $collection = $controller->queryRecord(fleetopsInternalQuoteVendorRequest([
        'pickup'      => 'place_intpickup1',
        'dropoff'     => 'place_intdropoff1',
        'facilitator' => 'integrated_vendor_intquote1',
    ]));
    expect($collection)->toBeInstanceOf(JsonResponse::class)
        ->and(json_encode($collection->getData(true)))->toContain('quote_intbridge1');
});

test('internal preliminary vendor bridge failures return 400 error responses', function () {
    fleetopsInternalQuoteVendorBoot();
    $GLOBALS['fleetopsInternalQuoteBridgeMode'] = 'fail';

    $response = (new ServiceQuoteController())->queryRecord(fleetopsInternalQuoteVendorRequest([
        'pickup'      => 'place_intpickup1',
        'dropoff'     => 'place_intdropoff1',
        'facilitator' => 'integrated_vendor_intquote1',
    ]));
    $GLOBALS['fleetopsInternalQuoteBridgeMode'] = 'ok';

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(400)
        ->and(json_encode($response->getData(true)))->toContain('internal vendor preliminary quote unavailable');
});
