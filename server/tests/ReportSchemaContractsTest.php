<?php

if (!interface_exists('Fleetbase\Support\Reporting\Contracts\ReportSchema')) {
    eval('namespace Fleetbase\Support\Reporting\Contracts; interface ReportSchema { public function registerReportSchema(\Fleetbase\Support\Reporting\ReportSchemaRegistry $registry): void; }');
}

if (!class_exists('Fleetbase\Support\Reporting\ReportSchemaRegistry')) {
    eval('namespace Fleetbase\Support\Reporting; class ReportSchemaRegistry { public array $tables = []; public function registerTable($table): void { $this->tables[$table->name] = $table; } }');
}

if (!class_exists('Fleetbase\Support\Reporting\Schema\Column')) {
    eval('namespace Fleetbase\Support\Reporting\Schema; class Column { public array $attributes = []; public mixed $transformer = null; public function __construct(public string $name, public string $type, public ?string $aggregate = null, public ?string $source = null) {} public static function make(string $name, string $type): self { return new self($name, $type); } public static function count(string $name, string $source): self { return new self($name, "count", "count", $source); } public static function sum(string $name, string $source): self { return new self($name, "sum", "sum", $source); } public static function avg(string $name, string $source): self { return new self($name, "avg", "avg", $source); } public function __call(string $method, array $arguments): self { $this->attributes[$method] = $arguments === [] ? true : (count($arguments) === 1 ? $arguments[0] : $arguments); return $this; } public function transformer(callable $transformer): self { $this->transformer = $transformer; return $this; } public function transform(mixed $value): mixed { return ($this->transformer)($value); } }');
}

if (!class_exists('Fleetbase\Support\Reporting\Schema\Relationship')) {
    eval('namespace Fleetbase\Support\Reporting\Schema; class Relationship { public array $attributes = []; public array $columns = []; public array $relationships = []; public function __construct(public string $name, public string $table) {} public static function hasAutoJoin(string $name, string $table): self { return new self($name, $table); } public function __call(string $method, array $arguments): self { $this->attributes[$method] = $arguments === [] ? true : (count($arguments) === 1 ? $arguments[0] : $arguments); return $this; } public function columns(array $columns): self { $this->columns = $columns; return $this; } public function with(array $relationships): self { $this->relationships = $relationships; return $this; } }');
}

if (!class_exists('Fleetbase\Support\Reporting\Schema\Table')) {
    eval('namespace Fleetbase\Support\Reporting\Schema; class Table { public array $attributes = []; public array $columns = []; public array $computedColumns = []; public array $relationships = []; public function __construct(public string $name) {} public static function make(string $name): self { return new self($name); } public function __call(string $method, array $arguments): self { $this->attributes[$method] = $arguments === [] ? true : (count($arguments) === 1 ? $arguments[0] : $arguments); return $this; } public function columns(array $columns): self { $this->columns = $columns; return $this; } public function computedColumns(array $columns): self { $this->computedColumns = $columns; return $this; } public function relationships(array $relationships): self { $this->relationships = $relationships; return $this; } }');
}

use Fleetbase\FleetOps\Support\Reporting\FleetOpsReportSchema;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;

function fleetOpsReportProperty(object $object, string $property, mixed $default = null): mixed
{
    $reflection = new ReflectionObject($object);

    while ($reflection) {
        if ($reflection->hasProperty($property)) {
            $propertyReflection = $reflection->getProperty($property);
            $propertyReflection->setAccessible(true);

            return $propertyReflection->getValue($object);
        }

        $reflection = $reflection->getParentClass();
    }

    return $default;
}

function fleetOpsReportCallOrProperty(object $object, string|array $methods, string $property, mixed $default = null): mixed
{
    foreach ((array) $methods as $method) {
        if (method_exists($object, $method)) {
            return $object->{$method}();
        }
    }

    return fleetOpsReportProperty($object, $property, $default);
}

function fleetOpsReportTables(ReportSchemaRegistry $registry): array
{
    if (method_exists($registry, 'getRegisteredTableNames') && method_exists($registry, 'getTable')) {
        $tables = [];

        foreach ($registry->getRegisteredTableNames() as $tableName) {
            $tables[$tableName] = $registry->getTable($tableName);
        }

        return $tables;
    }

    return fleetOpsReportProperty($registry, 'tables');
}

function fleetOpsReportTableMeta(Table $table, string $key): mixed
{
    $getter = match ($key) {
        'label'     => 'getLabel',
        'category'  => 'getCategory',
        'extension' => 'getExtension',
        'maxRows'   => 'getMaxRows',
        'cacheTtl'  => 'getCacheTtl',
        default     => null,
    };

    if ($getter && method_exists($table, $getter)) {
        return $table->{$getter}();
    }

    return fleetOpsReportProperty($table, 'attributes', [])[$key] ?? null;
}

function fleetOpsReportTableName(object $schema): string
{
    return fleetOpsReportCallOrProperty($schema, 'getTable', 'table');
}

function fleetOpsReportSchemaName(Table|Column|Relationship $schema): string
{
    return fleetOpsReportCallOrProperty($schema, 'getName', 'name');
}

function fleetOpsReportColumnFlag(Column $column, string $flag): bool
{
    $getter = match ($flag) {
        'searchable'   => 'isSearchable',
        'sortable'     => 'isSortable',
        'filterable'   => 'isFilterable',
        'aggregatable' => 'isAggregatable',
        'hidden'       => 'isHidden',
        'computed'     => 'isComputed',
        default        => null,
    };

    if ($getter && method_exists($column, $getter)) {
        return $column->{$getter}();
    }

    return (bool) (fleetOpsReportProperty($column, 'attributes', [])[$flag] ?? false);
}

function fleetOpsReportColumnAggregate(Column $column): ?string
{
    $aggregate = fleetOpsReportProperty($column, 'aggregate');

    if ($aggregate) {
        return $aggregate;
    }

    $computation = fleetOpsReportCallOrProperty($column, 'getComputation', 'computation');

    return match (true) {
        is_string($computation) && str_starts_with($computation, 'COUNT(') => 'count',
        is_string($computation) && str_starts_with($computation, 'SUM(')   => 'sum',
        is_string($computation) && str_starts_with($computation, 'AVG(')   => 'avg',
        default                                                            => null,
    };
}

function fleetOpsReportTransform(Column $column, mixed $value): mixed
{
    if (method_exists($column, 'transform')) {
        return $column->transform($value);
    }

    if (method_exists($column, 'transformValue')) {
        return $column->transformValue($value);
    }

    $transformer = fleetOpsReportProperty($column, 'transformer');

    return is_callable($transformer) ? $transformer($value) : $value;
}

function fleetOpsReportColumns(Table|Relationship $container): array
{
    return fleetOpsReportCallOrProperty($container, 'getColumns', 'columns', []);
}

function fleetOpsReportComputedColumns(Table $table): array
{
    return fleetOpsReportCallOrProperty($table, 'getComputedColumns', 'computedColumns', []);
}

function fleetOpsReportRelationships(Table|Relationship $container): array
{
    if ($container instanceof Relationship) {
        return fleetOpsReportCallOrProperty($container, 'getNestedRelationships', 'nestedRelationships', fleetOpsReportProperty($container, 'relationships', []));
    }

    return fleetOpsReportCallOrProperty($container, 'getRelationships', 'relationships', []);
}

function fleetOpsReportColumnsFromRelationships(array $relationships): array
{
    $columns = [];

    foreach ($relationships as $relationship) {
        array_push($columns, ...fleetOpsReportColumns($relationship));
        array_push($columns, ...fleetOpsReportColumnsFromRelationships(fleetOpsReportRelationships($relationship)));
    }

    return $columns;
}

function fleetOpsReportColumn(Table|Relationship $container, string $name): Column
{
    foreach ([...fleetOpsReportColumns($container), ...($container instanceof Table ? fleetOpsReportComputedColumns($container) : []), ...fleetOpsReportColumnsFromRelationships(fleetOpsReportRelationships($container))] as $column) {
        if (fleetOpsReportSchemaName($column) === $name) {
            return $column;
        }
    }

    throw new RuntimeException("Column {$name} was not registered.");
}

function fleetOpsReportRelationship(Table|Relationship $container, string $name): Relationship
{
    foreach (fleetOpsReportRelationships($container) as $relationship) {
        if (fleetOpsReportSchemaName($relationship) === $name) {
            return $relationship;
        }
    }

    throw new RuntimeException("Relationship {$name} was not registered.");
}

test('fleetops report schema registers every table with columns computed columns and relationships', function () {
    $registry = new ReportSchemaRegistry();

    (new FleetOpsReportSchema())->registerReportSchema($registry);

    $tables = fleetOpsReportTables($registry);

    expect(array_keys($tables))->toBe([
        'orders',
        'drivers',
        'vehicles',
        'assets',
        'places',
        'contacts',
        'vendors',
        'fuel_reports',
    ]);

    $orders = $tables['orders'];
    expect(fleetOpsReportTableMeta($orders, 'label'))->toBe('Orders')
        ->and(fleetOpsReportTableMeta($orders, 'category'))->toBe('Operations')
        ->and(fleetOpsReportTableMeta($orders, 'extension'))->toBe('fleet-ops')
        ->and(fleetOpsReportTableMeta($orders, 'maxRows'))->toBe(50000)
        ->and(fleetOpsReportTableMeta($orders, 'cacheTtl'))->toBe(3600)
        ->and(fleetOpsReportColumnFlag(fleetOpsReportColumn($orders, 'public_id'), 'searchable'))->toBeTrue()
        ->and(fleetOpsReportColumnAggregate(fleetOpsReportColumn($orders, 'total_orders')))->toBe('count')
        ->and(fleetOpsReportColumnAggregate(fleetOpsReportColumn($orders, 'total_transaction_amount')))->toBe('sum')
        ->and(fleetOpsReportColumnAggregate(fleetOpsReportColumn($orders, 'average_transaction_amount')))->toBe('avg');

    $payload = fleetOpsReportRelationship($orders, 'payload');
    expect(fleetOpsReportTableName($payload))->toBe('payloads')
        ->and(fleetOpsReportTableName(fleetOpsReportRelationship($payload, 'pickup')))->toBe('places')
        ->and(fleetOpsReportTableName(fleetOpsReportRelationship($payload, 'dropoff')))->toBe('places')
        ->and(fleetOpsReportTableName(fleetOpsReportRelationship($orders, 'transaction')))->toBe('transactions');

    expect(fleetOpsReportTableMeta($tables['drivers'], 'category'))->toBe('Personnel')
        ->and(fleetOpsReportTableName(fleetOpsReportRelationship($tables['drivers'], 'current_vehicle')))->toBe('vehicles')
        ->and(fleetOpsReportTableMeta($tables['vehicles'], 'category'))->toBe('Fleet')
        ->and(fleetOpsReportTableName(fleetOpsReportRelationship($tables['vehicles'], 'current_driver')))->toBe('drivers')
        ->and(fleetOpsReportTableMeta($tables['assets'], 'label'))->toBe('Trailers and Assets')
        ->and(fleetOpsReportTableMeta($tables['assets'], 'category'))->toBe('Fleet')
        ->and(fleetOpsReportColumnFlag(fleetOpsReportColumn($tables['assets'], 'asset_class'), 'filterable'))->toBeTrue()
        ->and(fleetOpsReportTableMeta($tables['places'], 'category'))->toBe('Geography')
        ->and(fleetOpsReportTableMeta($tables['contacts'], 'category'))->toBe('CRM')
        ->and(fleetOpsReportTableMeta($tables['vendors'], 'category'))->toBe('CRM')
        ->and(fleetOpsReportTableMeta($tables['fuel_reports'], 'category'))->toBe('Operations');
});

test('fleetops report schema transformers normalize labels booleans distances and money', function () {
    $registry = new ReportSchemaRegistry();

    (new FleetOpsReportSchema())->registerReportSchema($registry);

    $tables       = fleetOpsReportTables($registry);
    $orders       = $tables['orders'];
    $drivers      = $tables['drivers'];
    $vehicles     = $tables['vehicles'];
    $transaction  = fleetOpsReportRelationship($orders, 'transaction');

    expect(fleetOpsReportTransform(fleetOpsReportColumn($orders, 'status'), 'driver_assigned'))->toBe('Driver Assigned')
        ->and(fleetOpsReportTransform(fleetOpsReportColumn($orders, 'status'), 'custom_status'))->toBe('Custom_status')
        ->and(fleetOpsReportTransform(fleetOpsReportColumn($orders, 'distance'), 10))->toBe(6.21)
        ->and(fleetOpsReportTransform(fleetOpsReportColumn($orders, 'adhoc'), true))->toBe('Yes')
        ->and(fleetOpsReportTransform(fleetOpsReportColumn($orders, 'pod_required'), false))->toBe('No')
        ->and(fleetOpsReportTransform(fleetOpsReportColumn($drivers, 'status'), 'suspended'))->toBe('Suspended')
        ->and(fleetOpsReportTransform(fleetOpsReportColumn($drivers, 'online'), false))->toBe('No')
        ->and(fleetOpsReportTransform(fleetOpsReportColumn($vehicles, 'status'), 'out_of_service'))->toBe('Out of Service')
        ->and(fleetOpsReportTransform(fleetOpsReportColumn($transaction, 'amount'), 12345))->toBe('123.45')
        ->and(fleetOpsReportTransform(fleetOpsReportColumn($transaction, 'status'), 'refunded'))->toBe('Refunded');
});
