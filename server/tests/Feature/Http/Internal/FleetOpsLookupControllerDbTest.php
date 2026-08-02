<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\FleetOpsLookupController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the FleetOpsLookupController protected search helpers against
 * SQLite: contact and vendor name searches scoped to the session company,
 * and the integrated-vendor listing.
 */
function fleetopsLookupDbBoot(): SQLiteConnection
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'contacts'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'meta', '_key'],
        'vendors'            => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'type', 'meta', '_key'],
        'integrated_vendors' => ['uuid', 'public_id', 'company_uuid', 'provider', 'credentials', 'options', 'sandbox', 'status', '_key'],
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
    $connection->table('contacts')->insert([
        ['uuid' => 'contact-lookup-1', 'company_uuid' => 'company-1', 'name' => 'Lookup Customer'],
        ['uuid' => 'contact-lookup-2', 'company_uuid' => 'company-2', 'name' => 'Lookup Foreign'],
    ]);
    $connection->table('vendors')->insert([
        ['uuid' => 'vendor-lookup-1', 'company_uuid' => 'company-1', 'name' => 'Lookup Vendor'],
    ]);
    $connection->table('integrated_vendors')->insert([
        ['uuid' => 'iv-lookup-1', 'public_id' => 'integrated_vendor_lookupone', 'company_uuid' => 'company-1', 'provider' => 'lalamove'],
    ]);

    return $connection;
}

test('lookup helpers search company scoped contacts vendors and integrations', function () {
    fleetopsLookupDbBoot();

    $controller = new FleetOpsLookupController();
    $helper     = function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod(FleetOpsLookupController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };

    $contacts = $helper('searchContacts', 'Lookup', 10);
    expect($contacts)->toHaveCount(1)
        ->and($contacts->first()->name)->toBe('Lookup Customer');

    $vendors = $helper('searchVendors', 'Lookup', 10);
    expect($vendors)->toHaveCount(1)
        ->and($vendors->first()->name)->toBe('Lookup Vendor');

    $integrated = $helper('integratedVendors');
    expect($integrated)->toHaveCount(1)
        ->and($integrated->first()->provider)->toBe('lalamove');
});
