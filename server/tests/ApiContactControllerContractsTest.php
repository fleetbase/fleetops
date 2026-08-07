<?php

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    class_alias(Illuminate\Database\Eloquent\Model::class, 'Illuminate\Foundation\Auth\User');
}

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ContactController;
use Fleetbase\FleetOps\Http\Requests\CreateContactRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateContactRequest;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiContactControllerProbe extends ContactController
{
    public ?Contact $contact                        = null;
    public ?FleetOpsApiContactFake $seed            = null;
    public ?FleetOpsApiContactUserFake $relatedUser = null;
    public array $createdCandidates                 = [];
    public array $upserts                           = [];
    public array $placeLookups                      = [];
    public mixed $queryResults                      = null;
    public bool $contactNotFound                    = false;

    protected function newContact(array $input): Contact
    {
        $this->createdCandidates[] = $input;

        return $this->seed ?? new FleetOpsApiContactFake($input);
    }

    protected function updateOrCreateContact(array $where, array $input): Contact
    {
        $this->upserts[] = [$where, $input];

        $contact = new FleetOpsApiContactFake();
        $contact->setRawAttributes(array_merge(['uuid' => 'created-contact-uuid'], $input));

        return $contact;
    }

    protected function findContact(string $id): Contact
    {
        if ($this->contactNotFound) {
            throw new ModelNotFoundException();
        }

        $this->contact?->setAttribute('lookup_id', $id);

        return $this->contact;
    }

    protected function queryContacts(Request $request)
    {
        return $this->queryResults ?? [['uuid' => 'contact-uuid']];
    }

    protected function getPlaceUuid(string $table, array $where): ?string
    {
        $this->placeLookups[] = [$table, $where];

        return 'place-uuid';
    }

    protected function findRelatedUser(Contact $contact): ?User
    {
        return $this->relatedUser;
    }

    protected function contactResource(Contact $contact)
    {
        return ['resource' => 'contact', 'contact' => $contact];
    }

    protected function contactResourceCollection($results)
    {
        return ['collection' => 'contact', 'items' => $results];
    }

    protected function deletedContactResource(Contact $contact)
    {
        return ['resource' => 'deleted-contact', 'contact' => $contact];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }

    protected function apiError(string $message, int $status = 400)
    {
        return ['apiError' => $message, 'status' => $status];
    }
}

class FleetOpsApiContactFake extends Contact
{
    public array $updates               = [];
    public bool $flushedForTest         = false;
    public bool $deletedForTest         = false;
    public bool $identityCheckedForTest = false;
    public ?string $identityError       = null;

    public function assertCustomerIdentityIsAvailable(): void
    {
        $this->identityCheckedForTest = true;

        if ($this->identityError) {
            throw new RuntimeException($this->identityError);
        }
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }

    public function flushAttributesCache(): bool
    {
        $this->flushedForTest = true;

        return true;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

class FleetOpsApiContactUserFake extends User
{
    public bool $deletedForTest = false;

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

function fleetopsCreateContactRequest(array $input): CreateContactRequest
{
    return CreateContactRequest::create('/api/v1/contacts', 'POST', $input);
}

function fleetopsUpdateContactRequest(array $input): UpdateContactRequest
{
    return UpdateContactRequest::create('/api/v1/contacts/contact-public', 'PUT', $input);
}

test('api contact controller creates contacts with company context and normalized defaults', function () {
    session(['company' => 'company-uuid']);

    $seed             = new FleetOpsApiContactFake();
    $controller       = new FleetOpsApiContactControllerProbe();
    $controller->seed = $seed;

    $response = $controller->create(fleetopsCreateContactRequest([
        'name'         => 'Ada Contact',
        'title'        => 'Dispatcher',
        'email'        => 'ada@example.test',
        'phone'        => '+15551234567',
        'meta'         => ['tier' => 'gold'],
        'company_uuid' => 'spoofed-company',
        'ignored'      => 'not copied',
    ]));

    expect($response['resource'])->toBe('contact')
        ->and($seed->company_uuid)->toBe('company-uuid')
        ->and($seed->identityCheckedForTest)->toBeTrue()
        ->and($controller->createdCandidates[0])->toBe([
            'name'  => 'Ada Contact',
            'title' => 'Dispatcher',
            'email' => 'ada@example.test',
            'phone' => '+15551234567',
            'meta'  => ['tier' => 'gold'],
            'type'  => 'contact',
        ])
        ->and($controller->upserts[0][0])->toBe([
            'company_uuid' => 'company-uuid',
            'name'         => 'Ada Contact',
            'email'        => 'ada@example.test',
        ])
        ->and($controller->upserts[0][1])->toMatchArray([
            'name'  => 'Ada Contact',
            'title' => 'Dispatcher',
            'email' => 'ada@example.test',
            'phone' => '+15551234567',
            'type'  => 'contact',
            'meta'  => ['tier' => 'gold'],
        ])
        ->and($controller->upserts[0][1])->not->toHaveKey('company_uuid')
        ->and($controller->upserts[0][1])->not->toHaveKey('ignored');
});

test('api contact controller updates queries finds and deletes contacts', function () {
    session(['company' => 'company-uuid']);

    $contact = new FleetOpsApiContactFake();
    $contact->setRawAttributes([
        'uuid'      => 'contact-uuid',
        'name'      => 'Old Contact',
        'type'      => 'contact',
        'user_uuid' => 'user-uuid',
    ]);

    $relatedUser = new FleetOpsApiContactUserFake();

    $controller                = new FleetOpsApiContactControllerProbe();
    $controller->contact       = $contact;
    $controller->relatedUser   = $relatedUser;
    $controller->queryResults  = [['uuid' => 'contact-a'], ['uuid' => 'contact-b']];

    $updated = $controller->update('contact-public', fleetopsUpdateContactRequest([
        'name'         => 'Updated Contact',
        'type'         => 'customer',
        'title'        => 'Ops Lead',
        'email'        => 'updated@example.test',
        'phone'        => '+15551112222',
        'meta'         => ['tier' => 'silver'],
        'place'        => 'place-public',
        'company_uuid' => 'spoofed-company',
    ]));
    $query   = $controller->query(new Request(['limit' => 2]));
    $found   = $controller->find('contact-public');
    $deleted = $controller->delete('contact-public');

    expect($updated)->toBe(['resource' => 'contact', 'contact' => $contact])
        ->and($controller->placeLookups)->toBe([
            ['places', ['public_id' => 'place-public', 'company_uuid' => 'company-uuid']],
        ])
        ->and($contact->updates[0])->toBe([
            'name'       => 'Updated Contact',
            'type'       => 'customer',
            'title'      => 'Ops Lead',
            'email'      => 'updated@example.test',
            'phone'      => '+15551112222',
            'meta'       => ['tier' => 'silver'],
            'place_uuid' => 'place-uuid',
        ])
        ->and($contact->flushedForTest)->toBeTrue()
        ->and($query)->toBe([
            'collection' => 'contact',
            'items'      => [['uuid' => 'contact-a'], ['uuid' => 'contact-b']],
        ])
        ->and($found)->toBe(['resource' => 'contact', 'contact' => $contact])
        ->and($deleted)->toBe(['resource' => 'deleted-contact', 'contact' => $contact])
        ->and($contact->lookup_id)->toBe('contact-public')
        ->and($contact->deletedForTest)->toBeTrue()
        ->and($relatedUser->deletedForTest)->toBeTrue();
});

test('api contact controller reports contact identity and missing record errors', function () {
    $seed                = new FleetOpsApiContactFake();
    $seed->identityError = 'Customer already exists.';

    $controller       = new FleetOpsApiContactControllerProbe();
    $controller->seed = $seed;

    expect($controller->create(fleetopsCreateContactRequest([
        'name'  => 'Duplicate Contact',
        'email' => 'duplicate@example.test',
    ])))->toBe(['apiError' => 'Customer already exists.', 'status' => 400]);

    $controller                  = new FleetOpsApiContactControllerProbe();
    $controller->contactNotFound = true;

    $expectedJson = [
        'json'   => ['error' => 'Contact resource not found.'],
        'status' => 404,
    ];

    expect($controller->update('missing-contact', fleetopsUpdateContactRequest(['name' => 'Missing'])))->toBe($expectedJson)
        ->and($controller->delete('missing-contact'))->toBe($expectedJson)
        ->and($controller->find('missing-contact'))->toBe(['apiError' => 'Contact resource not found.', 'status' => 404]);
});
