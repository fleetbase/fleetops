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

function fleetOpsReportColumnsFromRelationships(array $relationships): array
{
    $columns = [];

    foreach ($relationships as $relationship) {
        array_push($columns, ...$relationship->columns);
        array_push($columns, ...fleetOpsReportColumnsFromRelationships($relationship->relationships));
    }

    return $columns;
}

function fleetOpsReportColumn(Table|Relationship $container, string $name): Column
{
    foreach ([...$container->columns, ...($container instanceof Table ? $container->computedColumns : []), ...fleetOpsReportColumnsFromRelationships($container->relationships)] as $column) {
        if ($column->name === $name) {
            return $column;
        }
    }

    throw new RuntimeException("Column {$name} was not registered.");
}

function fleetOpsReportRelationship(Table|Relationship $container, string $name): Relationship
{
    foreach ($container->relationships as $relationship) {
        if ($relationship->name === $name) {
            return $relationship;
        }
    }

    throw new RuntimeException("Relationship {$name} was not registered.");
}

test('fleetops report schema registers every table with columns computed columns and relationships', function () {
    $registry = new ReportSchemaRegistry();

    (new FleetOpsReportSchema())->registerReportSchema($registry);

    expect(array_keys($registry->tables))->toBe([
        'orders',
        'drivers',
        'vehicles',
        'places',
        'contacts',
        'vendors',
        'fuel_reports',
    ]);

    $orders = $registry->tables['orders'];
    expect($orders->attributes)
        ->toMatchArray([
            'label'       => 'Orders',
            'category'    => 'Operations',
            'extension'   => 'fleet-ops',
            'maxRows'     => 50000,
            'cacheTtl'    => 3600,
        ])
        ->and(fleetOpsReportColumn($orders, 'public_id')->attributes['searchable'])->toBeTrue()
        ->and(fleetOpsReportColumn($orders, 'total_orders')->aggregate)->toBe('count')
        ->and(fleetOpsReportColumn($orders, 'total_transaction_amount')->aggregate)->toBe('sum')
        ->and(fleetOpsReportColumn($orders, 'average_transaction_amount')->aggregate)->toBe('avg');

    $payload = fleetOpsReportRelationship($orders, 'payload');
    expect($payload->table)->toBe('payloads')
        ->and(fleetOpsReportRelationship($payload, 'pickup')->table)->toBe('places')
        ->and(fleetOpsReportRelationship($payload, 'dropoff')->table)->toBe('places')
        ->and(fleetOpsReportRelationship($orders, 'transaction')->table)->toBe('transactions');

    expect($registry->tables['drivers']->attributes['category'])->toBe('Personnel')
        ->and(fleetOpsReportRelationship($registry->tables['drivers'], 'current_vehicle')->table)->toBe('vehicles')
        ->and($registry->tables['vehicles']->attributes['category'])->toBe('Fleet')
        ->and(fleetOpsReportRelationship($registry->tables['vehicles'], 'current_driver')->table)->toBe('drivers')
        ->and($registry->tables['places']->attributes['category'])->toBe('Geography')
        ->and($registry->tables['contacts']->attributes['category'])->toBe('CRM')
        ->and($registry->tables['vendors']->attributes['category'])->toBe('CRM')
        ->and($registry->tables['fuel_reports']->attributes['category'])->toBe('Operations');
});

test('fleetops report schema transformers normalize labels booleans distances and money', function () {
    $registry = new ReportSchemaRegistry();

    (new FleetOpsReportSchema())->registerReportSchema($registry);

    $orders       = $registry->tables['orders'];
    $drivers      = $registry->tables['drivers'];
    $vehicles     = $registry->tables['vehicles'];
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
