<?php

use Fleetbase\FleetOps\Http\Resources\v1\Driver as DriverResource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FleetOpsDriverResourceRouteFixture
{
    public function __construct(private string $uri)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

class FleetOpsDriverResourceFixture implements ArrayAccess
{
    public bool $wasRecentlyCreated = false;

    public function __construct(private array $attributes)
    {
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->attributes);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[(string) $offset]);
    }

    public function relationLoaded(string $relationship): bool
    {
        return false;
    }

    public function loadMissing(string $relationship): self
    {
        return $this;
    }

    public function getOriginal(string $key): mixed
    {
        return $this->attributes['original'][$key] ?? $this->attributes[$key] ?? null;
    }

    public function withCustomFields(array $payload): array
    {
        return $payload;
    }
}

class TestFleetOpsDriverResource extends DriverResource
{
    protected function assignedOrdersCount(): int
    {
        return 4;
    }

    protected function currentOrderReference(): ?string
    {
        return 'order_current';
    }
}

function fleetopsDriverResourceRequest(bool $internal): Request
{
    $uri     = $internal ? 'api/int/v1/fleet-ops/drivers/driver_123' : 'api/v1/fleet-ops/drivers/driver_123';
    $request = Request::create('/' . $uri, 'GET');

    $request->setRouteResolver(fn () => new FleetOpsDriverResourceRouteFixture($uri));
    app()->instance('request', $request);
    app()->instance('redis', new class {
        private string $countryData = '{"currency":"SGD","capital":"Singapore"}';

        public function connection(): self
        {
            return $this;
        }

        public function exists(string $key): bool
        {
            return str_starts_with($key, 'countryData:');
        }

        public function get(string $key): string
        {
            return $this->countryData;
        }

        public function __call(string $method, array $arguments): mixed
        {
            return null;
        }
    });

    return $request;
}

function fleetopsDriverResourceFixture(array $overrides = []): FleetOpsDriverResourceFixture
{
    return new FleetOpsDriverResourceFixture(array_merge([
        'id'                             => 77,
        'uuid'                           => 'driver-uuid',
        'public_id'                      => 'driver_public',
        'internal_id'                    => 'DRV-77',
        'user_uuid'                      => 'user-uuid',
        'company_uuid'                   => 'company-uuid',
        'vehicle_uuid'                   => 'vehicle-uuid',
        'vendor_uuid'                    => 'vendor-uuid',
        'current_job_uuid'               => 'order-uuid',
        'user'                           => (object) ['public_id' => 'user_public'],
        'company'                        => (object) ['public_id' => 'company_public', 'name' => 'Company One'],
        'vehicle'                        => (object) ['public_id' => 'vehicle_public'],
        'currentJob'                     => (object) ['public_id' => 'order_public'],
        'vendor'                         => (object) ['public_id' => 'vendor_public'],
        'name'                           => 'Jane Driver',
        'email'                          => 'jane@example.test',
        'phone'                          => '+15551112222',
        'drivers_license_number'         => 'DL-77',
        'license_expiry'                 => Carbon::parse('2027-05-10'),
        'photo_url'                      => 'https://cdn.test/driver.png',
        'avatar_url'                     => 'https://cdn.test/avatar.png',
        'original'                       => ['avatar_url' => 'avatar-upload-token'],
        'vehicle_name'                   => 'Truck 77',
        'vehicle_avatar'                 => 'https://cdn.test/truck.png',
        'vendor_name'                    => 'Vendor One',
        'location'                       => ['type' => 'Point', 'coordinates' => [103.85, 1.29]],
        'heading'                        => 90,
        'altitude'                       => 12,
        'speed'                          => 45,
        'country'                        => 'SG',
        'currency'                       => 'SGD',
        'city'                           => 'Singapore',
        'online'                         => true,
        'status'                         => 'available',
        'token'                          => 'driver-token',
        'meta'                           => ['shift' => 'morning'],
        'updated_at'                     => '2026-07-01 10:00:00',
        'created_at'                     => '2026-01-01 09:00:00',
    ], $overrides));
}

test('driver resource exposes internal identifiers counters and assignment metadata', function () {
    $request = fleetopsDriverResourceRequest(true);
    $payload = (new TestFleetOpsDriverResource(fleetopsDriverResourceFixture()))->resolve($request);

    expect($payload)->toMatchArray([
        'id'                      => 77,
        'uuid'                    => 'driver-uuid',
        'public_id'               => 'driver_public',
        'user_uuid'               => 'user-uuid',
        'company_uuid'            => 'company-uuid',
        'vehicle_uuid'            => 'vehicle-uuid',
        'vendor_uuid'             => 'vendor-uuid',
        'current_job_uuid'        => 'order-uuid',
        'avatar_value'            => 'avatar-upload-token',
        'vehicle_name'            => 'Truck 77',
        'vendor_name'             => 'Vendor One',
        'assigned_orders_count'   => 4,
        'current_order_reference' => 'order_current',
        'drivers_license_number'  => 'DL-77',
        'license_expiry'          => '2027-05-10',
        'heading'                 => 90,
        'altitude'                => 12,
        'speed'                   => 45,
        'online'                  => true,
        'status'                  => 'available',
        'meta'                    => ['shift' => 'morning'],
    ]);
});

test('driver resource keeps public payload focused on public fields', function () {
    $request = fleetopsDriverResourceRequest(false);
    $payload = (new TestFleetOpsDriverResource(fleetopsDriverResourceFixture()))->resolve($request);

    expect($payload['id'])->toBe('driver_public')
        ->and($payload)->toMatchArray([
            'internal_id'            => 'DRV-77',
            'name'                   => 'Jane Driver',
            'email'                  => 'jane@example.test',
            'phone'                  => '+15551112222',
            'drivers_license_number' => 'DL-77',
            'license_expiry'         => '2027-05-10',
            'photo_url'              => 'https://cdn.test/driver.png',
            'avatar_url'             => 'https://cdn.test/avatar.png',
            'vehicle_avatar'         => 'https://cdn.test/truck.png',
            'country'                => 'SG',
            'currency'               => 'SGD',
            'city'                   => 'Singapore',
            'online'                 => true,
            'status'                 => 'available',
        ]);
});

test('driver resource webhook payload serializes driver assignment details', function () {
    $payload = (new TestFleetOpsDriverResource(fleetopsDriverResourceFixture()))->toWebhookPayload();

    expect($payload)->toMatchArray([
        'id'                     => 'driver_public',
        'internal_id'            => 'DRV-77',
        'name'                   => 'Jane Driver',
        'email'                  => 'jane@example.test',
        'phone'                  => '+15551112222',
        'photo_url'              => 'https://cdn.test/driver.png',
        'license_expiry'         => '2027-05-10',
        'vehicle'                => 'vehicle_public',
        'current_job'            => 'order_public',
        'vendor'                 => 'vendor_public',
        'heading'                => 90,
        'altitude'               => 12,
        'speed'                  => 45,
        'country'                => 'SG',
        'currency'               => 'SGD',
        'city'                   => 'Singapore',
        'online'                 => true,
        'status'                 => 'available',
        'meta'                   => ['shift' => 'morning'],
    ]);
});
