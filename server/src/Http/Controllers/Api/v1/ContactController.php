<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateContactRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateContactRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Contact as ContactResource;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\User;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * Creates a new Fleetbase Contact resource.
     *
     * @param \Fleetbase\Http\Requests\CreateContactRequest $request
     *
     * @return \Fleetbase\Http\Resources\Contact
     */
    public function create(CreateContactRequest $request)
    {
        // get request input
        $input = $this->contactCreateInputFromRequest($request);

        // Handle photo upload using FileResolverService
        if ($request->has('photo')) {
            $path = 'uploads/' . session('company') . '/contacts';
            $file = app(\Fleetbase\Services\FileResolverService::class)->resolve($request->input('photo'), $path);

            if ($file) {
                $input['photo_uuid'] = $file->uuid;
            }
        }

        try {
            $contactCandidate               = $this->newContact($input);
            $contactCandidate->company_uuid = session('company');
            $contactCandidate->assertCustomerIdentityIsAvailable();

            // create the contact
            $contact = $this->updateOrCreateContact(
                [
                    'company_uuid' => session('company'),
                    'name'         => $input['name'],
                    'email'        => $input['email'],
                ],
                $input
            );
        } catch (\Exception $e) {
            return $this->apiError($e->getMessage());
        }

        // response the driver resource
        return $this->contactResource($contact);
    }

    /**
     * Updates a Fleetbase Contact resource.
     *
     * @param string                                        $id
     * @param \Fleetbase\Http\Requests\UpdateContactRequest $request
     *
     * @return \Fleetbase\Http\Resources\Contact
     */
    public function update($id, UpdateContactRequest $request)
    {
        // find for the contact
        try {
            $contact = $this->findContact($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Contact resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $this->contactUpdateInputFromRequest($request);

        // If setting a default location for the contact
        if ($request->has('place')) {
            $input['place_uuid'] = $this->getPlaceUuid('places', [
                'public_id'    => $request->input('place'),
                'company_uuid' => session('company'),
            ]);
        }

        // Handle photo upload using FileResolverService
        if ($request->has('photo')) {
            $photo = $request->input('photo');

            // Handle removal key
            if ($photo === 'REMOVE') {
                $input['photo_uuid'] = null;
            } else {
                $path = 'uploads/' . session('company') . '/contacts';
                $file = app(\Fleetbase\Services\FileResolverService::class)->resolve($photo, $path);

                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }
        }

        try {
            // update the contact
            $contactCandidate = $contact->replicate();
            $contactCandidate->forceFill($contact->getAttributes());
            $contactCandidate->exists = $contact->exists;
            $contactCandidate->forceFill($input);
            $contactCandidate->assertCustomerIdentityIsAvailable();

            $contact->update($input);
        } catch (\Exception $e) {
            return $this->apiError($e->getMessage());
        }

        $contact->flushAttributesCache();

        // response the contact resource
        return $this->contactResource($contact);
    }

    /**
     * Query for Fleetbase Contact resources.
     *
     * @return \Fleetbase\Http\Resources\ContactCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryContacts($request);

        return $this->contactResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase Contact resources.
     *
     * @return \Fleetbase\Http\Resources\ContactCollection
     */
    public function find($id)
    {
        // find for the contact
        try {
            $contact = $this->findContact($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->apiError('Contact resource not found.', 404);
        }

        // response the contact resource
        return $this->contactResource($contact);
    }

    /**
     * Deletes a Fleetbase Contact resources.
     *
     * @return \Fleetbase\Http\Resources\ContactCollection
     */
    public function delete($id)
    {
        try {
            $contact = $this->findContact($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Contact resource not found.',
                ],
                404
            );
        }

        try {
            // delete the contact
            $contact->delete();

            // Delete related user if any
            $user = $this->findRelatedUser($contact);
            if ($user) {
                $user->delete();
            }
        } catch (\Exception $e) {
            return $this->apiError($e->getMessage());
        }

        // response the contact resource
        return $this->deletedContactResource($contact);
    }

    protected function contactCreateInputFromRequest(Request $request): array
    {
        $input          = $request->only(['name', 'type', 'title', 'email', 'phone', 'meta', 'type']);
        $input['phone'] = isset($input['phone']) && is_string($input['phone']) ? Utils::formatPhoneNumber($input['phone']) : ($input['phone'] ?? null);
        $input['type']  = empty($input['type']) ? 'contact' : $input['type'];

        return $input;
    }

    protected function contactUpdateInputFromRequest(Request $request): array
    {
        return $request->only(['name', 'type', 'title', 'email', 'phone', 'meta']);
    }

    protected function newContact(array $input): Contact
    {
        return new Contact($input);
    }

    protected function updateOrCreateContact(array $where, array $input): Contact
    {
        return Contact::updateOrCreate($where, $input);
    }

    protected function findContact(string $id): Contact
    {
        return Contact::findRecordOrFail($id);
    }

    protected function queryContacts(Request $request)
    {
        return Contact::queryWithRequest($request);
    }

    protected function getPlaceUuid(string $table, array $where): ?string
    {
        return Utils::getUuid($table, $where);
    }

    protected function findRelatedUser(Contact $contact): ?User
    {
        return User::where(['uuid' => $contact->user_uuid, 'type' => $contact->type])->first();
    }

    protected function contactResource(Contact $contact)
    {
        return new ContactResource($contact);
    }

    protected function contactResourceCollection($results)
    {
        return ContactResource::collection($results);
    }

    protected function deletedContactResource(Contact $contact)
    {
        return new DeletedResource($contact);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }

    protected function apiError(string $message, int $status = 400)
    {
        return response()->apiError($message, $status);
    }
}
