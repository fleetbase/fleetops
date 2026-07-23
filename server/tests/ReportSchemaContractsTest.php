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

function fleetOpsReportProperty(object $object, string $property): mixed
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

    throw new RuntimeException(sprintf('Property %s was not found on %s.', $property, $object::class));
}

function fleetOpsReportTables(ReportSchemaRegistry $registry): array
{
    return fleetOpsReportProperty($registry, 'tables');
}

function fleetOpsReportAttributes(object $schema): array
{
    return fleetOpsReportProperty($schema, 'attributes');
}

function fleetOpsReportTableName(object $schema): string
{
    return fleetOpsReportProperty($schema, 'table');
}

function fleetOpsReportColumnName(Column $column): string
{
    return fleetOpsReportProperty($column, 'name');
}

function fleetOpsReportColumnAggregate(Column $column): ?string
{
    return fleetOpsReportProperty($column, 'aggregate');
}

function fleetOpsReportColumns(Table|Relationship $container): array
{
    return fleetOpsReportProperty($container, 'columns');
}

function fleetOpsReportComputedColumns(Table $table): array
{
    return fleetOpsReportProperty($table, 'computedColumns');
}

function fleetOpsReportRelationships(Table|Relationship $container): array
{
    return fleetOpsReportProperty($container, 'relationships');
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
        if (fleetOpsReportColumnName($column) === $name) {
            return $column;
        }
    }

    throw new RuntimeException("Column {$name} was not registered.");
}

function fleetOpsReportRelationship(Table|Relationship $container, string $name): Relationship
{
    foreach (fleetOpsReportRelationships($container) as $relationship) {
        if (fleetOpsReportProperty($relationship, 'name') === $name) {
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
        'places',
        'contacts',
        'vendors',
        'fuel_reports',
    ]);

    $orders = $tables['orders'];
    expect(fleetOpsReportAttributes($orders))
        ->toMatchArray([
            'label'       => 'Orders',
            'category'    => 'Operations',
            'extension'   => 'fleet-ops',
            'maxRows'     => 50000,
            'cacheTtl'    => 3600,
        ])
        ->and(fleetOpsReportAttributes(fleetOpsReportColumn($orders, 'public_id'))['searchable'])->toBeTrue()
        ->and(fleetOpsReportColumnAggregate(fleetOpsReportColumn($orders, 'total_orders')))->toBe('count')
        ->and(fleetOpsReportColumnAggregate(fleetOpsReportColumn($orders, 'total_transaction_amount')))->toBe('sum')
        ->and(fleetOpsReportColumnAggregate(fleetOpsReportColumn($orders, 'average_transaction_amount')))->toBe('avg');

    $payload = fleetOpsReportRelationship($orders, 'payload');
    expect(fleetOpsReportTableName($payload))->toBe('payloads')
        ->and(fleetOpsReportTableName(fleetOpsReportRelationship($payload, 'pickup')))->toBe('places')
        ->and(fleetOpsReportTableName(fleetOpsReportRelationship($payload, 'dropoff')))->toBe('places')
        ->and(fleetOpsReportTableName(fleetOpsReportRelationship($orders, 'transaction')))->toBe('transactions');

    expect(fleetOpsReportAttributes($tables['drivers'])['category'])->toBe('Personnel')
        ->and(fleetOpsReportTableName(fleetOpsReportRelationship($tables['drivers'], 'current_vehicle')))->toBe('vehicles')
        ->and(fleetOpsReportAttributes($tables['vehicles'])['category'])->toBe('Fleet')
        ->and(fleetOpsReportTableName(fleetOpsReportRelationship($tables['vehicles'], 'current_driver')))->toBe('drivers')
        ->and(fleetOpsReportAttributes($tables['places'])['category'])->toBe('Geography')
        ->and(fleetOpsReportAttributes($tables['contacts'])['category'])->toBe('CRM')
        ->and(fleetOpsReportAttributes($tables['vendors'])['category'])->toBe('CRM')
        ->and(fleetOpsReportAttributes($tables['fuel_reports'])['category'])->toBe('Operations');
});

test('fleetops report schema transformers normalize labels booleans distances and money', function () {
    $registry = new ReportSchemaRegistry();

    (new FleetOpsReportSchema())->registerReportSchema($registry);

    $tables       = fleetOpsReportTables($registry);
    $orders       = $tables['orders'];
    $drivers      = $tables['drivers'];
    $vehicles     = $tables['vehicles'];
    $transaction  = fleetOpsReportRelationship($orders, 'transaction');

    expect(fleetOpsReportColumn($orders, 'status')->transform('driver_assigned'))->toBe('Driver Assigned')
        ->and(fleetOpsReportColumn($orders, 'status')->transform('custom_status'))->toBe('Custom_status')
        ->and(fleetOpsReportColumn($orders, 'distance')->transform(10))->toBe(6.21)
        ->and(fleetOpsReportColumn($orders, 'adhoc')->transform(true))->toBe('Yes')
        ->and(fleetOpsReportColumn($orders, 'pod_required')->transform(false))->toBe('No')
        ->and(fleetOpsReportColumn($drivers, 'status')->transform('suspended'))->toBe('Suspended')
        ->and(fleetOpsReportColumn($drivers, 'online')->transform(false))->toBe('No')
        ->and(fleetOpsReportColumn($vehicles, 'status')->transform('out_of_service'))->toBe('Out of Service')
        ->and(fleetOpsReportColumn($transaction, 'amount')->transform(12345))->toBe('123.45')
        ->and(fleetOpsReportColumn($transaction, 'status')->transform('refunded'))->toBe('Refunded');
});
