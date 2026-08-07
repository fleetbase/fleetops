<?php

use Fleetbase\FleetOps\Models\Sensor;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the Sensor model reading pipeline against SQLite: threshold alert
 * creation and resolution driven by recordReading, severity mapping and
 * alert message generation, and subject position creation.
 */
function fleetopsSensorReadingsBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());
    config()->set('auth.defaults.guard', 'web');
    config()->set('auth.guards.web.driver', 'token');
    config()->set('auth.guards.web.provider', 'users');
    config()->set('auth.providers.users.driver', 'eloquent');
    config()->set('auth.providers.users.model', Fleetbase\Models\User::class);
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->instance('hash', new class implements Illuminate\Contracts\Hashing\Hasher {
        public function info($hashedValue): array
        {
            return [];
        }

        public function make($value, array $options = []): string
        {
            return md5((string) $value);
        }

        public function check($value, $hashedValue, array $options = []): bool
        {
            return md5((string) $value) === $hashedValue;
        }

        public function needsRehash($hashedValue, array $options = []): bool
        {
            return false;
        }

        public function verifyConfiguration($value): bool
        {
            return true;
        }
    });
    Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');
    $authManager = new Illuminate\Auth\AuthManager(app());
    app()->instance('auth', $authManager);
    app()->instance(Illuminate\Auth\AuthManager::class, $authManager);
    app()->instance('request', Illuminate\Http\Request::create('/int/v1'));

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'sensors'   => ['uuid', 'public_id', 'company_uuid', 'device_uuid', 'name', 'sensor_type', 'unit', 'last_value', 'last_reading_at', 'min_threshold', 'max_threshold', 'status', 'meta'],
        'alerts'    => ['uuid', 'public_id', 'company_uuid', 'type', 'severity', 'status', 'subject_type', 'subject_uuid', 'message', 'context', 'triggered_at', 'resolved_at'],
        'users'     => ['uuid', 'public_id', 'company_uuid', 'name'],
        'positions' => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'destination_uuid', 'coordinates', 'heading', 'bearing', 'speed', 'altitude', 'order_uuid', '_key'],
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

    return $connection;
}

function fleetopsSensorReadingsSensor(SQLiteConnection $connection): Sensor
{
    $connection->table('sensors')->insert([
        'uuid'          => 'sensor-1',
        'company_uuid'  => 'company-1',
        'name'          => 'Cold Chain Temp',
        'sensor_type'   => 'temperature',
        'unit'          => 'C',
        'min_threshold' => '0',
        'max_threshold' => '10',
    ]);

    return Sensor::where('uuid', 'sensor-1')->withoutGlobalScopes()->first();
}

test('out of threshold readings open a single alert', function () {
    $connection = fleetopsSensorReadingsBoot();
    $sensor     = fleetopsSensorReadingsSensor($connection);

    expect($sensor->recordReading('15.5'))->toBeTrue()
        ->and($connection->table('alerts')->where('status', 'open')->count())->toBe(1)
        ->and($connection->table('alerts')->value('type'))->toBe('sensor_threshold');

    // A second out-of-threshold reading does not duplicate the open alert
    $sensor->recordReading('16.0');
    expect($connection->table('alerts')->count())->toBe(1);
});

test('normal readings resolve open threshold alerts', function () {
    $connection = fleetopsSensorReadingsBoot();
    $sensor     = fleetopsSensorReadingsSensor($connection);

    $sensor->recordReading('15.5');
    expect($connection->table('alerts')->where('status', 'open')->count())->toBe(1);

    $sensor->recordReading('5');
    expect($connection->table('alerts')->where('status', 'open')->count())->toBe(0)
        ->and($connection->table('alerts')->where('status', 'resolved')->count())->toBe(1);
});

test('severity mapping and alert messages reflect threshold status', function () {
    $connection = fleetopsSensorReadingsBoot();
    $sensor     = fleetopsSensorReadingsSensor($connection);

    $severity = new ReflectionMethod(Sensor::class, 'getSeverityForThresholdStatus');
    $severity->setAccessible(true);
    expect($severity->invoke($sensor, 'out_of_range'))->toBeString();

    $message = new ReflectionMethod(Sensor::class, 'generateThresholdAlertMessage');
    $message->setAccessible(true);
    expect($message->invoke($sensor, '15.5', $sensor->threshold_status))->toBeString();
});

test('create position persists a subject scoped position row', function () {
    $connection = fleetopsSensorReadingsBoot();
    $sensor     = fleetopsSensorReadingsSensor($connection);

    $position = $sensor->createPosition(['latitude' => 1.3, 'longitude' => 103.8, 'speed' => 12]);

    expect($position)->not->toBeNull()
        ->and($connection->table('positions')->count())->toBe(1)
        ->and($connection->table('positions')->value('subject_uuid'))->toBe('sensor-1');

    // Location-keyed attributes map onto coordinates
    $second = $sensor->createPosition(['location' => new Fleetbase\LaravelMysqlSpatial\Types\Point(1.31, 103.81)], 'destination-uuid-string');
    expect($connection->table('positions')->count())->toBe(2);
});
