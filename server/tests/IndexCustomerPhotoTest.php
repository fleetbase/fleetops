<?php

use Fleetbase\FleetOps\Http\Filter\OrderFilter;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Customer as IndexCustomerResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Customer;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\LiveOrderQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;

class FleetOpsIndexCustomerPhotoMorphTo extends MorphTo
{
    public array $morphWith = [];

    public function __construct()
    {
    }

    public function morphWith(array $with)
    {
        $this->morphWith = $with;

        return $this;
    }
}

class FleetOpsIndexCustomerPhotoBuilder extends Builder
{
    public array $with = [];

    public function __construct()
    {
    }

    public function __call($method, $parameters)
    {
        return $this;
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        return $this;
    }

    public function whereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        return $this;
    }

    public function whereNotIn($column, $values, $boolean = 'and')
    {
        return $this;
    }

    public function whereNull($columns, $boolean = 'and', $not = false)
    {
        return $this;
    }

    public function with($relations, $callback = null)
    {
        $this->with = array_merge($this->with, $relations);

        return $this;
    }
}

class FleetOpsIndexCustomerPhotoLiveQuery extends LiveOrderQuery
{
    public static FleetOpsIndexCustomerPhotoBuilder $builder;

    protected static function newOrderQuery(string $companyUuid): Builder
    {
        return static::$builder = new FleetOpsIndexCustomerPhotoBuilder();
    }
}

function fleetopsIndexCustomerPhotoExpectedMorphWith(): array
{
    return [
        Contact::class  => ['photo'],
        Customer::class => ['photo'],
        Vendor::class   => ['logo'],
    ];
}

function fleetopsIndexCustomerPhotoResolve(object $customer): array
{
    return (new IndexCustomerResource($customer))->resolve(Request::create('/', 'GET'));
}

test('index customer resource sends a contact photo url', function () {
    $contact = new Contact();
    $contact->setRawAttributes(['name' => 'Fixture Contact']);
    $contact->setRelation('photo', (object) ['url' => 'https://cdn.example.test/contact.png']);

    expect(fleetopsIndexCustomerPhotoResolve($contact)['photo_url'])->toBe('https://cdn.example.test/contact.png');
});

test('index customer resource sends a vendor logo as the photo url', function () {
    $vendor = new Vendor();
    $vendor->setRawAttributes(['name' => 'Fixture Vendor']);
    $vendor->setRelation('logo', (object) ['url' => 'https://cdn.example.test/vendor.png']);

    expect(fleetopsIndexCustomerPhotoResolve($vendor)['photo_url'])->toBe('https://cdn.example.test/vendor.png');
});

test('index customer resource sends a null photo url for a customer type without one', function () {
    $payload = fleetopsIndexCustomerPhotoResolve(new ArrayObject(['name' => 'Fixture'], ArrayObject::ARRAY_AS_PROPS));

    expect($payload)->toHaveKey('photo_url')
        ->and($payload['photo_url'])->toBeNull();
});

test('index customer eager loads the photo file for each customer type', function () {
    $relation = new FleetOpsIndexCustomerPhotoMorphTo();

    expect(IndexCustomerResource::withPhoto($relation))->toBe($relation)
        ->and($relation->morphWith)->toBe(fleetopsIndexCustomerPhotoExpectedMorphWith());
});

test('live order query eager loads customer photos', function () {
    FleetOpsIndexCustomerPhotoLiveQuery::make('company-uuid', ['with_relations' => true, 'apply_permissions' => false]);

    $relation = new FleetOpsIndexCustomerPhotoMorphTo();
    FleetOpsIndexCustomerPhotoLiveQuery::$builder->with['customer']($relation);

    expect($relation->morphWith)->toBe(fleetopsIndexCustomerPhotoExpectedMorphWith());
});

test('internal order filter eager loads customer photos', function () {
    $builder = new FleetOpsIndexCustomerPhotoBuilder();
    $request = Request::create('/int/v1/orders', 'GET');
    $session = app('session.store');
    $session->put('company', 'company_test');
    $request->setLaravelSession($session);

    $filter = new OrderFilter($request);
    $prop   = (new ReflectionClass($filter))->getParentClass()->getProperty('builder');
    $prop->setAccessible(true);
    $prop->setValue($filter, $builder);

    $filter->queryForInternal();

    $relation = new FleetOpsIndexCustomerPhotoMorphTo();
    $builder->with['customer']($relation);

    expect($relation->morphWith)->toBe(fleetopsIndexCustomerPhotoExpectedMorphWith());
});
