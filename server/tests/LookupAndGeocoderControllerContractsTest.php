<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!class_exists('Fleetbase\Http\Requests\Internal\BulkDeleteRequest', false)) {
    eval('namespace Fleetbase\Http\Requests\Internal; class BulkDeleteRequest extends \Illuminate\Http\Request {}');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\FleetOpsLookupController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\GeocoderController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\IntegratedVendorController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

if (!method_exists(Request::class, 'or')) {
    Request::macro('or', function (array $keys, mixed $default = null) {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return $this->input($key);
            }
        }

        return $default;
    });
}

class FleetOpsGeocoderControllerProbe extends GeocoderController
{
    public Collection $forwardResults;
    public Collection $reverseResults;
    public array $forwardCalls  = [];
    public array $reverseCalls  = [];
    public array $createdPlaces = [];

    public function __construct()
    {
        $this->forwardResults = collect();
        $this->reverseResults = collect();
    }

    protected function reverseGeocode(float $latitude, float $longitude)
    {
        $this->reverseCalls[] = [$latitude, $longitude];

        return $this->reverseResults;
    }

    protected function forwardGeocode(string $query)
    {
        $this->forwardCalls[] = $query;

        return $this->forwardResults;
    }

    protected function placeFromGoogleAddress($googleAddress)
    {
        $place = [
            'address' => $googleAddress->address,
            'source'  => $googleAddress->source,
        ];
        $this->createdPlaces[] = $place;

        return $place;
    }
}

class FleetOpsLookupControllerProbe extends FleetOpsLookupController
{
    public Collection $contacts;
    public Collection $vendors;
    public Collection $integrated;
    public array $calls = [];

    public function __construct()
    {
        $this->contacts   = collect();
        $this->vendors    = collect();
        $this->integrated = collect();
    }

    protected function searchContacts(?string $query, int|string $limit)
    {
        $this->calls[] = ['contacts', $query, $limit];

        return $this->contacts;
    }

    protected function searchVendors(?string $query, int|string $limit)
    {
        $this->calls[] = ['vendors', $query, $limit];

        return $this->vendors;
    }

    protected function integratedVendors()
    {
        $this->calls[] = ['integrated'];

        return $this->integrated;
    }
}

class FleetOpsIntegratedVendorControllerProbe extends IntegratedVendorController
{
    public Collection $supported;
    public FleetOpsIntegratedVendorQueryFake $query;
    public array $queryIds      = [];
    public array $errors        = [];
    public array $jsonResponses = [];

    public function __construct()
    {
        $this->supported = collect();
        $this->query     = new FleetOpsIntegratedVendorQueryFake();
    }

    protected function supportedIntegratedVendors()
    {
        return $this->supported;
    }

    protected function integratedVendorQuery(array $ids)
    {
        $this->queryIds[] = $ids;

        return $this->query;
    }

    protected function jsonResponse(array $payload, int $status = 200)
    {
        $this->jsonResponses[] = [$payload, $status];

        return ['json' => $payload, 'status' => $status];
    }

    protected function errorResponse(string $message)
    {
        $this->errors[] = $message;

        return ['error' => $message];
    }
}

class FleetOpsIntegratedVendorQueryFake
{
    public int $count   = 0;
    public int $deleted = 0;

    public function count(): int
    {
        return $this->count;
    }

    public function delete(): int
    {
        return $this->deleted;
    }
}

function fleetopsLookupContact(string $name): Contact
{
    $contact = new class extends Contact {
        public function toArray(): array
        {
            return $this->getAttributes();
        }
    };
    $contact->setRawAttributes(['uuid' => strtolower(str_replace(' ', '-', $name)), 'name' => $name], true);

    return $contact;
}

function fleetopsLookupVendor(string $name): Vendor
{
    $vendor = new class extends Vendor {
        public function toArray(): array
        {
            return $this->getAttributes();
        }
    };
    $vendor->setRawAttributes(['uuid' => strtolower(str_replace(' ', '-', $name)), 'name' => $name], true);

    return $vendor;
}

function fleetopsLookupIntegratedVendor(string $name): IntegratedVendor
{
    $vendor = new class extends IntegratedVendor {
        public function toArray(): array
        {
            return $this->getAttributes();
        }
    };
    $vendor->setRawAttributes(['uuid' => strtolower(str_replace(' ', '-', $name)), 'name' => $name], true);

    return $vendor;
}

test('geocoder controller handles invalid empty single and multiple reverse geocode responses', function () {
    $controller = new FleetOpsGeocoderControllerProbe();

    $defaultPoint = $controller->reverse(new Request(['coordinates' => 'not-coordinates']));
    $empty        = $controller->reverse(new Request(['coordinates' => [1.3, 103.8]]));

    $controller->reverseResults = collect([
        (object) ['address' => 'One Way', 'source' => 'reverse-a'],
        (object) ['address' => 'Two Way', 'source' => 'reverse-b'],
    ]);

    $single = $controller->reverse(new Request(['coordinates' => [1.3, 103.8], 'single' => true]));
    $many   = $controller->reverse(new Request(['coordinates' => [1.3, 103.8]]));

    expect($defaultPoint->getData(true))->toBe([])
        ->and($empty->getData(true))->toBe([])
        ->and($single->getData(true))->toBe(['address' => 'One Way', 'source' => 'reverse-a'])
        ->and($many->getData(true))->toBe([
            ['address' => 'One Way', 'source' => 'reverse-a'],
            ['address' => 'Two Way', 'source' => 'reverse-b'],
        ])
        ->and($controller->reverseCalls)->toBe([
            [0.0, 0.0],
            [1.3, 103.8],
            [1.3, 103.8],
            [1.3, 103.8],
        ]);
});

test('geocoder controller handles forward geocode array delegation and result shapes', function () {
    $controller                 = new FleetOpsGeocoderControllerProbe();
    $controller->forwardResults = collect([
        (object) ['address' => 'Forward One', 'source' => 'forward-a'],
        (object) ['address' => 'Forward Two', 'source' => 'forward-b'],
    ]);
    $controller->reverseResults = collect([
        (object) ['address' => 'Reverse One', 'source' => 'reverse-a'],
    ]);

    $single    = $controller->geocode(new Request(['query' => 'depot', 'single' => true]));
    $many      = $controller->geocode(new Request(['query' => 'depot']));
    $delegated = $controller->geocode(new Request(['query' => [1.3, 103.8], 'single' => true]));

    $emptyController = new FleetOpsGeocoderControllerProbe();
    $empty           = $emptyController->geocode(new Request(['query' => 'missing']));

    expect($single->getData(true))->toBe(['address' => 'Forward One', 'source' => 'forward-a'])
        ->and($many->getData(true))->toBe([
            ['address' => 'Forward One', 'source' => 'forward-a'],
            ['address' => 'Forward Two', 'source' => 'forward-b'],
        ])
        ->and($delegated->getData(true))->toBe(['address' => 'Reverse One', 'source' => 'reverse-a'])
        ->and($empty->getData(true))->toBe([])
        ->and($controller->forwardCalls)->toBe(['depot', 'depot']);
});

test('fleet ops lookup controller merges polymorphic contacts vendors and integrated facilitators', function () {
    session(['company' => 'company-uuid']);

    $controller             = new FleetOpsLookupControllerProbe();
    $controller->contacts   = collect([fleetopsLookupContact('Beta Contact')]);
    $controller->vendors    = collect([fleetopsLookupVendor('Alpha Vendor')]);
    $controller->integrated = collect([fleetopsLookupIntegratedVendor('Zulu Integrated')]);

    $response = $controller->polymorphs(Request::create('/int/v1/fleet-ops/facilitators', 'GET', [
        'q'     => 'vendor',
        'limit' => 7,
    ]));

    $data = $response->getData(true);

    expect($data['facilitators'])->toHaveCount(3)
        ->and($data['facilitators'][0])->toBe([
            'uuid'             => 'zulu-integrated',
            'name'             => 'Zulu Integrated',
            'facilitator_type' => 'integrated-vendor',
            'type'             => 'facilitator',
        ])
        ->and($data['facilitators'][1])->toMatchArray([
            'uuid' => 'alpha-vendor',
            'name' => 'Alpha Vendor',
            'type' => 'facilitator',
        ])
        ->and($data['facilitators'][2])->toMatchArray([
            'uuid' => 'beta-contact',
            'name' => 'Beta Contact',
            'type' => 'facilitator',
        ])
        ->and($data['facilitators'][1]['facilitator_type'])->toContain('lookupandgeocodercontrollercontractstest')
        ->and($data['facilitators'][2]['facilitator_type'])->toContain('lookupandgeocodercontrollercontractstest')
        ->and($controller->calls)->toBe([
            ['contacts', 'vendor', 7],
            ['vendors', 'vendor', 7],
            ['integrated'],
        ]);
});

test('integrated vendor controller returns supported vendors', function () {
    $controller            = new FleetOpsIntegratedVendorControllerProbe();
    $controller->supported = collect([
        new class {
            public function toArray(): array
            {
                return ['key' => 'lalamove', 'name' => 'Lalamove'];
            }
        },
    ]);

    $supported = $controller->getSupported(new Request());

    expect($supported->getData(true))->toBe([['key' => 'lalamove', 'name' => 'Lalamove']])
        ->and($controller->queryIds)->toBe([]);
});

test('integrated vendor controller bulk deletes selected vendors', function () {
    $controller                 = new FleetOpsIntegratedVendorControllerProbe();
    $controller->query->count   = 2;
    $controller->query->deleted = 2;

    $response = $controller->bulkDelete(new Fleetbase\Http\Requests\Internal\BulkDeleteRequest([
        'ids' => ['vendor-a', 'vendor-b'],
    ]));

    expect($response)->toBe([
        'json' => [
            'status'  => 'OK',
            'message' => 'Deleted 2 integrated vendors',
        ],
        'status' => 200,
    ])->and($controller->queryIds)->toBe([['vendor-a', 'vendor-b']])
        ->and($controller->errors)->toBe([]);
});

test('integrated vendor controller reports empty or failed bulk deletes', function () {
    $empty = new FleetOpsIntegratedVendorControllerProbe();

    expect($empty->bulkDelete(new Fleetbase\Http\Requests\Internal\BulkDeleteRequest()))->toBe([
        'error' => 'Nothing to delete.',
    ])->and($empty->queryIds)->toBe([]);

    $failed               = new FleetOpsIntegratedVendorControllerProbe();
    $failed->query->count = 2;

    expect($failed->bulkDelete(new Fleetbase\Http\Requests\Internal\BulkDeleteRequest([
        'ids' => ['vendor-a', 'vendor-b'],
    ])))->toBe([
        'error' => 'Failed to bulk delete vendors.',
    ])->and($failed->queryIds)->toBe([['vendor-a', 'vendor-b']]);
});
