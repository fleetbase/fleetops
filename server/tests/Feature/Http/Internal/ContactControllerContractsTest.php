<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ContactController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\VendorPersonnel;
use Illuminate\Http\Request;

class FleetOpsInternalContactEndpointControllerProbe extends ContactController
{
    public ?Contact $trashedContact    = null;
    public ?Contact $customerContact   = null;
    public ?Contact $conversionContact = null;
    public ?Vendor $createdVendor      = null;
    public array $vendorCreates        = [];
    public array $personnelUpserts     = [];
    public array $transactions         = [];
    public array $bulkUpdates          = [];
    public array $resourceVendors      = [];
    public array $issues               = [];

    protected function contactByUuidWithTrashed(string $id): ?Contact
    {
        return $this->trashedContact;
    }

    protected function contactByUuid(string $id): ?Contact
    {
        return $this->customerContact;
    }

    protected function contactForVendorConversion(string $id): Contact
    {
        $this->conversionContact->setAttribute('lookup_id', $id);

        return $this->conversionContact;
    }

    protected function runContactConversionTransaction(callable $callback): mixed
    {
        $this->transactions[] = 'begin';
        $result               = $callback();
        $this->transactions[] = 'commit';

        return $result;
    }

    protected function createVendorFromContact(array $attributes): Vendor
    {
        $this->vendorCreates[] = $attributes;

        return $this->createdVendor;
    }

    protected function updateOrCreateVendorPersonnel(array $where, array $attributes): VendorPersonnel
    {
        $this->personnelUpserts[] = [$where, $attributes];

        $personnel = new VendorPersonnel();
        $personnel->setRawAttributes(array_merge($where, $attributes), true);

        return $personnel;
    }

    protected function vendorResourcePayload(Vendor $vendor): array
    {
        $this->resourceVendors[] = $vendor->uuid;

        return [
            'uuid'      => $vendor->uuid,
            'public_id' => $vendor->public_id,
            'name'      => $vendor->name,
        ];
    }

    protected function bulkUpdateCustomerContext(string $modelClass, array $filter, array $replacement): void
    {
        $this->bulkUpdates[] = [$modelClass, $filter, $replacement];
    }

    protected function customerPortalIssuesForContact(Contact $contact)
    {
        return collect($this->issues);
    }
}

class FleetOpsInternalContactEndpointFake extends Contact
{
    public array $updates = [];

    public function jsonSerialize(): mixed
    {
        return $this->getAttributes();
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

class FleetOpsInternalContactEndpointIssueFake extends Issue
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

function fleetopsInternalContactEndpointContact(array $attributes = []): FleetOpsInternalContactEndpointFake
{
    $contact = new FleetOpsInternalContactEndpointFake();
    $contact->setRawAttributes(array_merge([
        'uuid'         => 'contact-uuid',
        'public_id'    => 'contact_public',
        'company_uuid' => 'company-uuid',
        'place_uuid'   => 'place-uuid',
        'name'         => 'Ada Contact',
        'email'        => 'ada@example.test',
        'phone'        => '+15551234567',
        'type'         => 'lead',
        'meta'         => ['customer_portal' => ['customer_uuid' => 'contact-uuid']],
    ], $attributes), true);

    return $contact;
}

function fleetopsInternalContactEndpointVendor(array $attributes = []): Vendor
{
    $vendor = new Vendor();
    $vendor->setRawAttributes(array_merge([
        'uuid'      => 'vendor-uuid',
        'public_id' => 'vendor_public',
        'name'      => 'Vendor Co.',
    ], $attributes), true);

    return $vendor;
}

test('internal contact controller returns facilitator and customer contact aliases', function () {
    $controller                  = new FleetOpsInternalContactEndpointControllerProbe();
    $controller->trashedContact  = fleetopsInternalContactEndpointContact(['uuid' => 'trashed-contact']);
    $controller->customerContact = fleetopsInternalContactEndpointContact(['uuid' => 'customer-contact']);

    $facilitator = $controller->getAsFacilitator('trashed-contact')->getData(true);
    $customer    = $controller->getAsCustomer('customer-contact')->getData(true);

    $controller->trashedContact  = null;
    $controller->customerContact = null;

    $missingFacilitator = $controller->getAsFacilitator('missing')->getData(true);
    $missingCustomer    = $controller->getAsCustomer('missing')->getData(true);

    expect($facilitator['facilitatorContact']['uuid'])->toBe('trashed-contact')
        ->and($customer['customerContact']['uuid'])->toBe('customer-contact')
        ->and($missingFacilitator)->toBe(['error' => 'Facilitator not found.'])
        ->and($missingCustomer)->toBe(['error' => 'Customer not found.']);
});

test('internal contact controller converts contacts into customer vendors', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);

    $contact = fleetopsInternalContactEndpointContact();
    $vendor  = fleetopsInternalContactEndpointVendor([
        'name' => 'Converted Vendor',
    ]);
    $issue   = new FleetOpsInternalContactEndpointIssueFake();
    $issue->setRawAttributes([
        'uuid' => 'issue-uuid',
        'meta' => [
            'customer_portal' => [
                'customer_uuid' => 'contact-uuid',
                'customer_type' => 'contact',
                'keep'          => true,
            ],
        ],
    ], true);

    $controller                    = new FleetOpsInternalContactEndpointControllerProbe();
    $controller->conversionContact = $contact;
    $controller->createdVendor     = $vendor;
    $controller->issues            = [$issue];

    $response = $controller->convertToVendor(new Request([
        'name'  => 'Converted Vendor',
        'email' => 'vendor@example.test',
        'phone' => '+15557654321',
    ]), 'contact_public');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['vendor'])->toBe([
            'uuid'      => 'vendor-uuid',
            'public_id' => 'vendor_public',
            'name'      => 'Converted Vendor',
        ])
        ->and($contact->lookup_id)->toBe('contact_public')
        ->and($controller->transactions)->toBe(['begin', 'commit'])
        ->and($controller->vendorCreates[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'place_uuid'   => 'place-uuid',
            'name'         => 'Converted Vendor',
            'email'        => 'vendor@example.test',
            'phone'        => '+15557654321',
            'status'       => 'active',
            'type'         => 'customer',
        ])
        ->and($controller->vendorCreates[0]['meta'])->toMatchArray([
            'converted_from_contact_uuid' => 'contact-uuid',
            'converted_from_contact_type' => 'lead',
            'converted_by_uuid'           => 'user-uuid',
        ])
        ->and($controller->personnelUpserts)->toBe([[
            ['vendor_uuid' => 'vendor-uuid', 'contact_uuid' => 'contact-uuid'],
            ['role' => 'admin', 'status' => 'active', 'invited_by_uuid' => 'user-uuid'],
        ]])
        ->and($controller->bulkUpdates)->toHaveCount(3)
        ->and($contact->updates[0]['type'])->toBe('customer')
        ->and($contact->updates[0]['meta'])->toMatchArray([
            'converted_from_type'           => 'lead',
            'converted_to_vendor_uuid'      => 'vendor-uuid',
            'converted_to_vendor_public_id' => 'vendor_public',
        ])
        ->and($issue->updates[0]['meta'])->toBe([
            'customer_portal' => [
                'customer_uuid' => 'vendor-uuid',
                'customer_type' => 'vendor',
                'keep'          => true,
            ],
        ])
        ->and($controller->resourceVendors)->toBe(['vendor-uuid']);
});
