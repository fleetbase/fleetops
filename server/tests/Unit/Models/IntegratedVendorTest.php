<?php

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

if (!function_exists('Fleetbase\FleetOps\Models\config')) {
    eval('namespace Fleetbase\FleetOps\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\url')) {
    eval('namespace Fleetbase\FleetOps\Models; function url($path = null) { return "https://api.example/" . ltrim((string) $path, "/"); }');
}

use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\SQLiteConnection;

class FleetOpsIntegratedVendorProviderFake
{
    public function getServiceTypes(): array
    {
        return ['MOTORCYCLE', 'VAN'];
    }

    public function getCountries(): array
    {
        return ['SG', 'MY'];
    }

    public function toArray(): array
    {
        return ['code' => 'fake_vendor', 'sandbox' => true];
    }

    public function getName(): string
    {
        return 'Fake Vendor';
    }

    public function getLogo(): string
    {
        return 'https://cdn.example/fake-vendor.png';
    }
}

class FleetOpsIntegratedVendorFake extends IntegratedVendor
{
    public FleetOpsIntegratedVendorProviderFake $providerFake;
    public mixed $apiFake = null;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->providerFake = new FleetOpsIntegratedVendorProviderFake();
    }

    public function provider()
    {
        return $this->providerFake;
    }

    public function api()
    {
        return $this->apiFake ?? (object) ['bridge' => true];
    }
}

function fleetopsIntegratedVendorUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsIntegratedVendor(array $attributes = []): FleetOpsIntegratedVendorFake
{
    $vendor = new FleetOpsIntegratedVendorFake();
    $vendor->setRawAttributes(array_merge([
        'provider'    => 'fake_vendor',
        'credentials' => ['api_key' => 'secret-key', 'account' => 'fleetbase'],
        'options'     => [],
    ], $attributes), true);
    $vendor->setAppends([]);

    return $vendor;
}

test('integrated vendor relationship contracts resolve expected relation types', function () {
    fleetopsIntegratedVendorUseRelationConnection();

    $vendor = new IntegratedVendor();

    expect($vendor->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($vendor->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($vendor->company())->toBeInstanceOf(BelongsTo::class)
        ->and($vendor->company()->getRelated())->toBeInstanceOf(Company::class);
});

test('integrated vendor accessors proxy provider details and static values', function () {
    $vendor = fleetopsIntegratedVendor();

    expect($vendor->serviceTypes())->toBe(['MOTORCYCLE', 'VAN'])
        ->and($vendor->service_types)->toBe(['MOTORCYCLE', 'VAN'])
        ->and($vendor->countries())->toBe(['SG', 'MY'])
        ->and($vendor->supported_countries)->toBe(['SG', 'MY'])
        ->and($vendor->provider_settings)->toBe(['code' => 'fake_vendor', 'sandbox' => true])
        ->and($vendor->name)->toBe('Fake Vendor')
        ->and($vendor->photo_url)->toBe('https://cdn.example/fake-vendor.png')
        ->and($vendor->logo_url)->toBe('https://cdn.example/fake-vendor.png')
        ->and($vendor->status)->toBe('active')
        ->and($vendor->type)->toBe('integrated-vendor')
        ->and($vendor->api())->toEqual((object) ['bridge' => true]);
});

test('integrated vendor credentials and webhook mutation keep current contracts', function () {
    $vendor = fleetopsIntegratedVendor();

    expect($vendor->getCredential('api_key'))->toBe('secret-key')
        ->and($vendor->getCredential('account'))->toBe('fleetbase');

    $vendor->webhook_url = 'https://hooks.example/listener';

    expect($vendor->webhook_url)->toBe('https://hooks.example/listener');
});
