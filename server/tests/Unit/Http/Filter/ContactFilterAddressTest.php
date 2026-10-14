<?php

use Fleetbase\FleetOps\Http\Filter\ContactFilter;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Http\Filter\Filter;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

afterEach(function () {
    EloquentModel::unsetConnectionResolver();
});

function contactFilterAddressBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    $tables = [
        'contacts' => ['uuid', 'public_id', 'company_uuid', 'place_uuid', 'name'],
        'places'   => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'name', 'street1', 'street2', 'country', 'province', 'district', 'city', 'postal_code', 'phone'],
    ];
    foreach ($tables as $table => $columns) {
        $connection->getSchemaBuilder()->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    $connection->table('places')->insert([
        ['uuid' => 'place-1', 'company_uuid' => 'co-1', 'street1' => '1 Harbour Road', 'city' => 'Singapore'],
        ['uuid' => 'place-2', 'company_uuid' => 'co-1', 'street1' => '9 Orchard Lane', 'city' => 'Singapore'],
    ]);
    $connection->table('contacts')->insert([
        ['uuid' => 'contact-1', 'company_uuid' => 'co-1', 'place_uuid' => 'place-1', 'name' => 'Harbour Contact'],
        ['uuid' => 'contact-2', 'company_uuid' => 'co-1', 'place_uuid' => 'place-2', 'name' => 'Orchard Contact'],
        ['uuid' => 'contact-3', 'company_uuid' => 'co-1', 'place_uuid' => null, 'name' => 'Placeless Contact'],
    ]);

    return $connection;
}

test('the contact address filter matches the address the contact list shows', function () {
    contactFilterAddressBoot();

    $builder  = Contact::query();
    $filter   = (new ReflectionClass(ContactFilter::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(Filter::class, 'builder');
    $property->setAccessible(true);
    $property->setValue($filter, $builder);

    $filter->address('harbour');

    expect($builder->pluck('uuid')->all())->toBe(['contact-1']);
});
