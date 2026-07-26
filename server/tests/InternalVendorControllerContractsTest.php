<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\VendorController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\VendorPersonnel;
use Illuminate\Http\Request;

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

class FleetOpsInternalVendorControllerContractsProbe extends VendorController
{
    public ?Vendor $trashedVendor         = null;
    public ?Driver $driver                = null;
    public ?Vendor $vendor                = null;
    public ?Vendor $vendorById            = null;
    public ?Contact $contactById          = null;
    public ?Contact $personnelContact     = null;
    public array $statuses                = [];
    public array $createdPersonnelContact = [];
    public array $upsertedPersonnel       = [];
    public array $deletedPersonnel        = [];
    public $personnels;

    protected function findVendorWithTrashedByUuid(string $id): ?Vendor
    {
        $this->trashedVendor?->setAttribute('lookup_id', $id);

        return $this->trashedVendor;
    }

    protected function vendorStatuses()
    {
        return collect($this->statuses);
    }

    protected function findDriverByUuid(string $uuid): ?Driver
    {
        $this->driver?->setAttribute('lookup_id', $uuid);

        return $this->driver;
    }

    protected function findVendorByUuid(string $uuid): ?Vendor
    {
        $this->vendor?->setAttribute('lookup_id', $uuid);

        return $this->vendor;
    }

    protected function findVendorByIdOrFail(string $id): Vendor
    {
        $this->vendorById?->setAttribute('lookup_id', $id);

        return $this->vendorById;
    }

    protected function findContactByIdOrFail(string $id): Contact
    {
        $this->contactById?->setAttribute('lookup_id', $id);

        return $this->contactById;
    }

    protected function queryVendorPersonnel(string $vendorUuid)
    {
        return $this->personnels ?? collect();
    }

    protected function updateOrCreateVendorPersonnel(array $where, array $attributes): VendorPersonnel
    {
        $this->upsertedPersonnel[] = [$where, $attributes];

        $personnel = new FleetOpsInternalVendorPersonnelFake();
        $personnel->setRawAttributes(array_merge($where, $attributes));
        $personnel->setRelation('contact', $this->personnelContact);

        return $personnel;
    }

    protected function deleteVendorPersonnel(string $vendorUuid, string $contactUuid): void
    {
        $this->deletedPersonnel[] = [$vendorUuid, $contactUuid];
    }

    protected function findPersonnelContact(string $contactId): Contact
    {
        $this->personnelContact?->setAttribute('lookup_id', $contactId);

        return $this->personnelContact;
    }

    protected function createPersonnelContact(array $attributes): Contact
    {
        $this->createdPersonnelContact = $attributes;

        $contact = new FleetOpsInternalVendorContactFake();
        $contact->setRawAttributes(array_merge(['uuid' => 'new-contact-uuid', 'public_id' => 'contact_new'], $attributes));
        $this->personnelContact = $contact;

        return $contact;
    }

    protected function contactResourcePayload(Contact $contact): array
    {
        return [
            'id'    => $contact->public_id,
            'uuid'  => $contact->uuid,
            'name'  => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ];
    }
}

class FleetOpsInternalVendorModelFake extends Vendor
{
    public function toArray()
    {
        return $this->getAttributes();
    }
}

class FleetOpsInternalVendorDriverFake extends Driver
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }
}

class FleetOpsInternalVendorContactFake extends Contact
{
    public bool $userCreated = false;

    public function createUser(bool $sendInvite = false): Fleetbase\Models\User
    {
        $this->userCreated = true;

        return new Fleetbase\Models\User();
    }

    public function toArray()
    {
        return $this->getAttributes();
    }

    public function getPhotoUrlAttribute(): string
    {
        return $this->attributes['photo_url'] ?? '';
    }
}

class FleetOpsInternalVendorPersonnelFake extends VendorPersonnel
{
    public array $loaded = [];

    public function load($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }
}

class FleetOpsInternalVendorUuidRequest extends Request
{
    public function isUuid(string $key): bool
    {
        return is_string($this->input($key)) && preg_match('/^[0-9a-fA-F-]{36}$/', $this->input($key)) === 1;
    }
}

function fleetopsInternalVendorJson(mixed $response): array
{
    return $response->getData(true);
}

test('internal vendor controller returns facilitator customer and status contracts', function () {
    session(['company' => 'company-uuid']);

    $vendor = new FleetOpsInternalVendorModelFake();
    $vendor->setRawAttributes(['uuid' => 'vendor-uuid', 'public_id' => 'vendor_public']);

    $controller                = new FleetOpsInternalVendorControllerContractsProbe();
    $controller->trashedVendor = $vendor;
    $controller->statuses      = ['active', null, 'paused'];

    $missingController = new FleetOpsInternalVendorControllerContractsProbe();

    expect(fleetopsInternalVendorJson($controller->getAsFacilitator('vendor-uuid'))['facilitatorVendor'])->toMatchArray([
        'uuid'      => 'vendor-uuid',
        'lookup_id' => 'vendor-uuid',
    ])
        ->and(fleetopsInternalVendorJson($controller->getAsCustomer('customer-uuid'))['customerVendor'])->toMatchArray([
            'uuid'      => 'vendor-uuid',
            'lookup_id' => 'customer-uuid',
        ])
        ->and(fleetopsInternalVendorJson($missingController->getAsFacilitator('missing'))['error'])->toBe('Facilitator not found.')
        ->and(fleetopsInternalVendorJson($missingController->getAsCustomer('missing'))['error'])->toBe('Customer not found.')
        ->and(fleetopsInternalVendorJson($controller->statuses()))->toBe(['active', null, 'paused']);
});

test('internal vendor controller assigns and removes drivers with validation branches', function () {
    $driver = new FleetOpsInternalVendorDriverFake();
    $driver->setRawAttributes(['uuid' => '11111111-1111-4111-8111-111111111111']);
    $vendor = new FleetOpsInternalVendorModelFake();
    $vendor->setRawAttributes(['uuid' => '22222222-2222-4222-8222-222222222222']);

    $controller         = new FleetOpsInternalVendorControllerContractsProbe();
    $controller->driver = $driver;
    $controller->vendor = $vendor;

    $assigned = $controller->assignDriver('22222222-2222-4222-8222-222222222222', new FleetOpsInternalVendorUuidRequest([
        'driver' => '11111111-1111-4111-8111-111111111111',
    ]));
    $removed = $controller->removeDriver('22222222-2222-4222-8222-222222222222', new FleetOpsInternalVendorUuidRequest([
        'driver' => '11111111-1111-4111-8111-111111111111',
    ]));

    $missingDriver         = new FleetOpsInternalVendorControllerContractsProbe();
    $missingDriver->vendor = $vendor;
    $missingVendor         = new FleetOpsInternalVendorControllerContractsProbe();
    $missingVendor->driver = $driver;

    expect(fleetopsInternalVendorJson($assigned))->toBe(['status' => 'ok'])
        ->and($driver->updates[0])->toBe(['vendor_uuid' => '22222222-2222-4222-8222-222222222222'])
        ->and(fleetopsInternalVendorJson($removed))->toBe(['status' => 'ok'])
        ->and($driver->updates[1])->toBe(['vendor_uuid' => null])
        ->and(fleetopsInternalVendorJson($controller->assignDriver('vendor', new FleetOpsInternalVendorUuidRequest(['driver' => 'not-a-uuid'])))['error'])->toBe('No driver selected to assign to vendor.')
        ->and(fleetopsInternalVendorJson($controller->removeDriver('vendor', new FleetOpsInternalVendorUuidRequest(['driver' => 'not-a-uuid'])))['error'])->toBe('No driver selected to remove from vendor.')
        ->and(fleetopsInternalVendorJson($missingDriver->assignDriver('22222222-2222-4222-8222-222222222222', new FleetOpsInternalVendorUuidRequest(['driver' => '11111111-1111-4111-8111-111111111111'])))['error'])->toBe('Selected driver cannot be found.')
        ->and(fleetopsInternalVendorJson($missingDriver->removeDriver('22222222-2222-4222-8222-222222222222', new FleetOpsInternalVendorUuidRequest(['driver' => '11111111-1111-4111-8111-111111111111'])))['error'])->toBe('Selected driver cannot be found.')
        ->and(fleetopsInternalVendorJson($missingVendor->assignDriver('22222222-2222-4222-8222-222222222222', new FleetOpsInternalVendorUuidRequest(['driver' => '11111111-1111-4111-8111-111111111111'])))['error'])->toBe('Vendor attempting to assign driver to is invalid.')
        ->and(fleetopsInternalVendorJson($missingVendor->removeDriver('22222222-2222-4222-8222-222222222222', new FleetOpsInternalVendorUuidRequest(['driver' => '11111111-1111-4111-8111-111111111111'])))['error'])->toBe('Vendor attempting to remove driver from is invalid.');
});

test('internal vendor controller lists adds and removes vendor personnel', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);

    $vendor = new FleetOpsInternalVendorModelFake();
    $vendor->setRawAttributes(['uuid' => 'vendor-uuid', 'public_id' => 'vendor_public']);
    $contact = new FleetOpsInternalVendorContactFake();
    $contact->setRawAttributes([
        'uuid'      => 'contact-uuid',
        'public_id' => 'contact_public',
        'name'      => 'Ada Contact',
        'email'     => 'ada@example.test',
        'phone'     => '+6555550000',
    ]);

    $personnel = new FleetOpsInternalVendorPersonnelFake();
    $personnel->setRawAttributes([
        'vendor_uuid'     => 'vendor-uuid',
        'contact_uuid'    => 'contact-uuid',
        'role'            => 'manager',
        'status'          => 'active',
        'invited_by_uuid' => 'inviter-uuid',
    ]);
    $personnel->setRelation('contact', $contact);

    $controller                   = new FleetOpsInternalVendorControllerContractsProbe();
    $controller->vendorById       = $vendor;
    $controller->contactById      = $contact;
    $controller->personnelContact = $contact;
    $controller->personnels       = collect([$personnel]);

    $listed        = fleetopsInternalVendorJson($controller->vendorPersonnels('vendor_public'));
    $addedExisting = fleetopsInternalVendorJson($controller->addVendorPersonnel(new Request([
        'contact'      => 'contact_public',
        'role'         => 'owner',
        'status'       => 'pending',
        'create_login' => true,
    ]), 'vendor_public'));
    $removed = fleetopsInternalVendorJson($controller->removeVendorPersonnel('vendor_public', 'contact_public'));

    $createdController             = new FleetOpsInternalVendorControllerContractsProbe();
    $createdController->vendorById = $vendor;
    $created                       = fleetopsInternalVendorJson($createdController->addVendorPersonnel(new Request([
        'name'         => 'New Contact',
        'email'        => 'new@example.test',
        'phone'        => '+6555551111',
        'create_login' => false,
    ]), 'vendor_public'));

    expect($listed['personnels'][0])->toMatchArray([
        'id'              => 'contact_public',
        'uuid'            => 'contact-uuid',
        'contact_uuid'    => 'contact-uuid',
        'public_id'       => 'contact_public',
        'name'            => 'Ada Contact',
        'email'           => 'ada@example.test',
        'phone'           => '+6555550000',
        'role'            => 'manager',
        'status'          => 'active',
        'invited_by_uuid' => 'inviter-uuid',
    ])
        ->and($addedExisting['personnel'])->toMatchArray([
            'contact_uuid'    => 'contact-uuid',
            'role'            => 'owner',
            'status'          => 'pending',
            'invited_by_uuid' => 'user-uuid',
        ])
        ->and($contact->userCreated)->toBeTrue()
        ->and($controller->upsertedPersonnel[0])->toBe([
            ['vendor_uuid' => 'vendor-uuid', 'contact_uuid' => 'contact-uuid'],
            ['role' => 'owner', 'status' => 'pending', 'invited_by_uuid' => 'user-uuid'],
        ])
        ->and($removed)->toBe(['status' => 'ok'])
        ->and($controller->deletedPersonnel)->toBe([['vendor-uuid', 'contact-uuid']])
        ->and($createdController->createdPersonnelContact)->toBe([
            'company_uuid' => 'company-uuid',
            'name'         => 'New Contact',
            'email'        => 'new@example.test',
            'phone'        => '+6555551111',
            'type'         => 'customer',
        ])
        ->and($created['personnel'])->toMatchArray([
            'contact_uuid' => 'new-contact-uuid',
            'role'         => 'member',
            'status'       => 'active',
        ]);
});
