<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\MorphController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FleetOpsMorphControllerProbe extends MorphController
{
    public FleetOpsMorphQueryFake $contacts;
    public FleetOpsMorphQueryFake $vendors;
    public FleetOpsMorphIntegratedVendorQueryFake $integratedVendors;

    public function __construct()
    {
        $this->contacts          = new FleetOpsMorphQueryFake();
        $this->vendors           = new FleetOpsMorphQueryFake();
        $this->integratedVendors = new FleetOpsMorphIntegratedVendorQueryFake();
    }

    protected function newContactQuery()
    {
        return $this->contacts;
    }

    protected function newVendorQuery()
    {
        return $this->vendors;
    }

    protected function newIntegratedVendorQuery(string $companyUuid)
    {
        $this->integratedVendors->companyUuid = $companyUuid;

        return $this->integratedVendors;
    }

    protected function currentUrl(): string
    {
        return 'https://fleetops.test/internal/v1/morph';
    }

    protected function newLengthAwarePaginator($items, int $total, int $limit, int $page, array $options)
    {
        return new FleetOpsMorphLengthAwarePaginatorFake($items, $total, $limit, $page, $options);
    }

    protected function jsonResponse(mixed $data)
    {
        return ['json' => $data];
    }

    protected function contactResource($resource)
    {
        return ['resource' => 'contact', 'item' => $resource];
    }

    protected function vendorResource($resource)
    {
        return ['resource' => 'vendor', 'item' => $resource];
    }

    protected function contactResourceCollection($resource)
    {
        return ['collection' => 'contact', 'items' => $resource->getCollection()->all()];
    }

    protected function vendorResourceCollection($resource)
    {
        return ['collection' => 'vendor', 'items' => $resource->getCollection()->all()];
    }
}

class FleetOpsMorphQueryFake
{
    public array $calls    = [];
    public array $items    = [];
    public ?int $limit     = null;
    public ?array $columns = null;

    public function searchWhere(string $column, mixed $query): self
    {
        $this->calls[] = ['searchWhere', $column, $query];

        return $this;
    }

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function applyDirectivesForPermissions(string $permission): self
    {
        $this->calls[] = ['permission', $permission];

        return $this;
    }

    public function filter(object $filter): self
    {
        $this->calls[] = ['filter', $filter::class];

        return $this;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function get(): Collection
    {
        return collect(array_slice($this->items, 0, $this->limit ?? count($this->items)));
    }

    public function fastPaginate(int $limit, array $columns): FleetOpsMorphPaginatorFake
    {
        $this->limit   = $limit;
        $this->columns = $columns;

        return new FleetOpsMorphPaginatorFake($this->items);
    }
}

class FleetOpsMorphIntegratedVendorQueryFake
{
    public ?string $companyUuid = null;
    public array $items         = [];

    public function get(): Collection
    {
        return collect($this->items);
    }
}

class FleetOpsMorphPaginatorFake
{
    public Collection $collection;

    public function __construct(array $items)
    {
        $this->collection = collect($items);
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function setCollection(Collection $collection): void
    {
        $this->collection = $collection;
    }

    public function first(): mixed
    {
        return $this->collection->first();
    }
}

class FleetOpsMorphLengthAwarePaginatorFake
{
    public Collection $items;

    public function __construct(Collection $items, public int $total, public int $limit, public int $page, public array $options)
    {
        $this->items = $items->values();
    }

    public function items(): array
    {
        return $this->items->all();
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->limit;
    }

    public function currentPage(): int
    {
        return $this->page;
    }

    public function lastPage(): int
    {
        return (int) ceil($this->total / $this->limit);
    }

    public function nextPageUrl(): ?string
    {
        return $this->page < $this->lastPage() ? $this->options['path'] . '?page=' . ($this->page + 1) : null;
    }

    public function previousPageUrl(): ?string
    {
        return $this->page > 1 ? $this->options['path'] . '?page=' . ($this->page - 1) : null;
    }

    public function firstItem(): ?int
    {
        return $this->items->isEmpty() ? null : (($this->page - 1) * $this->limit) + 1;
    }

    public function lastItem(): ?int
    {
        return $this->items->isEmpty() ? null : $this->firstItem() + $this->items->count() - 1;
    }
}

class FleetOpsMorphResourceFake implements ArrayAccess
{
    public function __construct(public array $attributes)
    {
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->attributes);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }
}

test('morph controller combines contacts vendors and integrated facilitators with pagination metadata', function () {
    session(['company' => 'company-uuid']);

    $controller                      = new FleetOpsMorphControllerProbe();
    $controller->contacts->items     = [
        new FleetOpsMorphResourceFake(['name' => 'Zed Contact', 'uuid' => 'contact-uuid']),
    ];
    $controller->vendors->items      = [
        new FleetOpsMorphResourceFake(['name' => 'Alpha Vendor', 'uuid' => 'vendor-uuid']),
    ];
    $controller->integratedVendors->items = [
        new FleetOpsMorphResourceFake(['name' => 'Integrated Vendor', 'uuid' => 'integrated-uuid']),
    ];

    $request  = Request::create('/internal/v1/morph/facilitators', 'GET', [
        'query' => 'alpha',
        'limit' => 2,
        'page'  => 1,
    ]);
    $response = $controller->queryCustomersOrFacilitators($request);

    expect($controller->integratedVendors->companyUuid)->toBe('company-uuid')
        ->and($controller->contacts->calls)->toContain(['searchWhere', 'name', 'alpha'])
        ->and($controller->vendors->calls)->toContain(['permission', 'fleet-ops list vendor'])
        ->and($response['json']['facilitators'])->toHaveCount(2)
        ->and($response['json']['facilitators'][0]['facilitator_type'])->toBe('integrated-vendor')
        ->and($response['json']['facilitators'][0]['type'])->toBe('facilitator')
        ->and($response['json']['facilitators'][1]['name'])->toBe('Alpha Vendor')
        ->and($response['json']['meta'])->toMatchArray([
            'total'        => 2,
            'per_page'     => 2,
            'current_page' => 1,
        ]);
});

test('morph controller returns the first combined customer when single is requested', function () {
    session(['company' => 'company-uuid']);

    $controller                  = new FleetOpsMorphControllerProbe();
    $controller->contacts->items = [
        new FleetOpsMorphResourceFake(['name' => 'Beta Contact', 'uuid' => 'contact-uuid']),
    ];
    $controller->vendors->items  = [
        new FleetOpsMorphResourceFake(['name' => 'Alpha Vendor', 'uuid' => 'vendor-uuid']),
    ];

    $request = Request::create('/internal/v1/morph/customers', 'GET', [
        'query'  => 'a',
        'single' => true,
    ]);

    expect($controller->queryCustomersOrFacilitators($request))->toBe([
        'json' => [
            'name'          => 'Alpha Vendor',
            'uuid'          => 'vendor-uuid',
            'customer_type' => 'fleetopsmorphresourcefake',
        ],
    ]);
});

test('morph controller queries contact customers and wraps single contact resources', function () {
    session(['company' => 'company-uuid']);

    $controller                  = new FleetOpsMorphControllerProbe();
    $controller->contacts->items = [
        new FleetOpsMorphResourceFake(['name' => 'Customer Contact', 'uuid' => 'contact-uuid']),
    ];

    $request = new Request([
        'query'     => 'customer',
        'limit'     => 5,
        'columns'   => ['uuid', 'name'],
        'single'    => true,
        'type'      => 'contact',
        'user_uuid' => 'user-uuid',
    ]);

    $response = $controller->queryCustomers($request);

    expect($controller->contacts->limit)->toBe(5)
        ->and($controller->contacts->columns)->toBe(['uuid', 'name'])
        ->and($controller->contacts->calls)->toContain(['searchWhere', 'name', 'customer'])
        ->and($controller->contacts->calls)->toContain(['where', ['user_uuid', 'user-uuid']])
        ->and($response['resource'])->toBe('contact')
        ->and($response['item']['customer_type'])->toBe('contact');
});

test('morph controller queries vendor customers and facilitator resource collections', function () {
    session(['company' => 'company-uuid']);

    $controller                 = new FleetOpsMorphControllerProbe();
    $controller->vendors->items = [
        new FleetOpsMorphResourceFake(['name' => 'Vendor Customer', 'uuid' => 'vendor-customer']),
    ];

    $customerResponse = $controller->queryCustomers(new Request([
        'query'   => 'vendor',
        'limit'   => 3,
        'columns' => ['uuid'],
        'type'    => 'vendor',
    ]));

    expect($customerResponse['collection'])->toBe('vendor')
        ->and($customerResponse['items'][0]['customer_type'])->toBe('vendor')
        ->and($controller->vendors->calls)->toContain(['permission', 'fleet-ops list vendor']);

    $controller                 = new FleetOpsMorphControllerProbe();
    $controller->vendors->items = [
        new FleetOpsMorphResourceFake(['name' => 'Facilitator Vendor', 'uuid' => 'vendor-facilitator']),
    ];

    $facilitatorResponse = $controller->queryFacilitators(new Request([
        'query'   => 'vendor',
        'columns' => ['uuid'],
    ]));

    expect($facilitatorResponse['collection'])->toBe('vendor')
        ->and($facilitatorResponse['items'][0]['facilitator_type'])->toBe('vendor');
});

test('morph controller queries contact facilitators and wraps single resources', function () {
    session(['company' => 'company-uuid']);

    $controller                  = new FleetOpsMorphControllerProbe();
    $controller->contacts->items = [
        new FleetOpsMorphResourceFake(['name' => 'Contact Facilitator', 'uuid' => 'contact-facilitator']),
    ];

    $response = $controller->queryFacilitators(new Request([
        'query'   => 'contact',
        'columns' => ['uuid'],
        'single'  => true,
        'type'    => 'contact',
    ]));

    expect($response['resource'])->toBe('contact')
        ->and($response['item']['facilitator_type'])->toBe('contact')
        ->and($controller->contacts->calls)->toContain(['permission', 'fleet-ops list contact']);
});
