<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SettingController;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class FleetOpsStorageDeadlineConnection extends MySqlConnection
{
    public array $queries   = [];
    public array $responses = [];
    public bool $maria      = false;

    public function getReadPdo()
    {
        return new class($this->maria) extends PDO {
            public function __construct(private bool $maria)
            {
            }

            public function getAttribute(int $attribute): mixed
            {
                if ($attribute !== PDO::ATTR_SERVER_VERSION) {
                    throw new LogicException('Only the database server version should be read');
                }

                return $this->maria ? '10.11.8-MariaDB' : '8.0.40';
            }
        };
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        $this->queries[] = [$query, $bindings];
        $response        = array_shift($this->responses) ?? [];
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

function fleetopsStorageQueryError(int $code): QueryException
{
    $error            = new PDOException('Database query failed');
    $error->errorInfo = ['HY000', $code, 'Database query failed'];

    return new QueryException('mysql', 'select history', [], $error);
}

beforeEach(function () {
    $this->originalDb = app()->bound('db') ? app('db') : null;
    $this->connection = new FleetOpsStorageDeadlineConnection(new PDO('sqlite::memory:'), '', '', ['driver' => 'mysql']);
    $connection       = $this->connection;
    app()->instance('db', new class($connection) {
        public function __construct(public $database)
        {
        }

        public function connection($name = null)
        {
            return $this->database;
        }

        public function __call($method, $args)
        {
            return $this->database->{$method}(...$args);
        }
    });
    DB::clearResolvedInstance('db');
    $this->controller = new SettingController();
    $this->invoke     = fn ($method, ...$arguments) => (new ReflectionMethod(SettingController::class, $method))->invoke($this->controller, ...$arguments);
    $this->scope      = fn ($query) => $query->where('company_uuid', 'company-a');
});

afterEach(function () {
    if ($this->originalDb) {
        app()->instance('db', $this->originalDb);
    } else {
        app()->forgetInstance('db');
    }
    DB::clearResolvedInstance('db');
});

test('storage scans enforce driver specific deadlines while preserving bindings', function (bool $maria) {
    $this->connection->maria     = $maria;
    $this->connection->responses = [[(object) ['row_count' => '4', 'oldest' => '2026-01-01']], [(object) ['rows' => 4]]];
    expect(($this->invoke)('tableUsage', 'device_events', $this->scope, 'created_at'))->toBe(['rows' => 4, 'oldest' => '2026-01-01']);
    ($this->invoke)('storageUsageSelect', 'select * from device_events where company_uuid = ?', ['company-a'], true);
    expect($this->connection->queries[0][1])->toBe(['company-a'])
        ->and($this->connection->queries[0][0])->toStartWith($maria ? 'SET STATEMENT max_statement_time=1 FOR select' : 'SELECT /*+ MAX_EXECUTION_TIME(1000) */')
        ->and($this->connection->queries[1][0])->toStartWith($maria ? 'SET STATEMENT max_statement_time=1 FOR EXPLAIN select' : 'EXPLAIN SELECT /*+ MAX_EXECUTION_TIME(1000) */')
        ->and($this->connection->queries[1][1])->toBe(['company-a']);
})->with([false, true]);

test('timed out counts use a scoped query plan estimate instead of another history scan', function () {
    $this->connection->responses = [fleetopsStorageQueryError(3024), [(object) ['rows' => 101, 'filtered' => 25]]];
    expect(($this->invoke)('tableUsage', 'device_events', $this->scope, 'created_at'))->toBe(['rows' => 25, 'oldest' => null, 'rows_estimated' => true])
        ->and($this->connection->queries[1][0])->toStartWith('EXPLAIN SELECT')
        ->and($this->connection->queries[1][1])->toBe(['company-a']);
    $this->connection->responses = [fleetopsStorageQueryError(1969), fleetopsStorageQueryError(1969)];
    expect(($this->invoke)('tableUsage', 'device_events', $this->scope, 'created_at'))->toBe(['rows' => null, 'oldest' => null, 'rows_estimated' => true]);
});

test('storage queries suppress only timeout errors and retain real database failures', function () {
    foreach (['tableUsage', 'tableOldest'] as $method) {
        $this->connection->responses = [fleetopsStorageQueryError(1146)];
        expect(fn () => ($this->invoke)($method, 'device_events', $this->scope, 'created_at'))->toThrow(QueryException::class);
    }
    $this->connection->responses = [fleetopsStorageQueryError(3024), fleetopsStorageQueryError(1146)];
    expect(fn () => ($this->invoke)('tableUsage', 'device_events', $this->scope, 'created_at'))->toThrow(QueryException::class);
    $this->connection->responses = [fleetopsStorageQueryError(3024)];
    expect(($this->invoke)('tableOldest', 'device_events', $this->scope, 'created_at'))->toBeNull();
    $this->connection->responses = [fleetopsStorageQueryError(1969)];
    expect(($this->invoke)('storageTableStatistics', ['device_events']))->toBe([]);
    $this->connection->responses = [fleetopsStorageQueryError(1146)];
    expect(fn () => ($this->invoke)('storageTableStatistics', ['device_events']))->toThrow(QueryException::class);
});
