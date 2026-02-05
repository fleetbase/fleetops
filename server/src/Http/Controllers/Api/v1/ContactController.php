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
        $input          = $request->only(['name', 'type', 'title', 'email', 'phone', 'meta', 'type']);
        $input['phone'] = is_string($input['phone']) ? Utils::formatPhoneNumber($input['phone']) : $input['phone'];
        $input['type']  = empty($input['type']) ? 'contact' : $input['type'];

        // Handle photo upload using FileResolverService
        if ($request->has('photo')) {
            $path = 'uploads/' . session('company') . '/contacts';
            $file = app(\Fleetbase\Services\FileResolverService::class)->resolve($request->input('photo'), $path);

            if ($file) {
                $input['photo_uuid'] = $file->uuid;
            }
        }

        try {
            // create the contact
            $contact = Contact::updateOrCreate(
                [
                    'company_uuid' => session('company'),
                    'name'         => $input['name'],
                    'email'        => $input['email'],
                ],
                $input
            );
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        // response the driver resource
        return new ContactResource($contact);
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
            $contact = Contact::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Contact resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->only(['name', 'type', 'title', 'email', 'phone', 'meta']);

        // If setting a default location for the contact
        if ($request->has('place')) {
            $input['place_uuid'] = Utils::getUuid('places', [
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

        // update the contact
        $contact->update($input);
        $contact->flushAttributesCache();

        // response the contact resource
        return new ContactResource($contact);
    }

    /**
     * Query for Fleetbase Contact resources.
     *
     * @return \Fleetbase\Http\Resources\ContactCollection
     */
    public function query(Request $request)
    {
        $results = Contact::queryWithRequest($request);

        return ContactResource::collection($results);
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
            $contact = Contact::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->apiError('Contact resource not found.', 404);
        }

        // response the contact resource
        return new ContactResource($contact);
    }

    /**
     * Deletes a Fleetbase Contact resources.
     *
     * @return \Fleetbase\Http\Resources\ContactCollection
     */
    public function delete($id)
    {
        try {
            $contact = Contact::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
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
            $user = User::where(['uuid' => $contact->user_uuid, 'type' => $contact->type])->first();
            if ($user) {
                $user->delete();
            }
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        // response the contact resource
        return new DeletedResource($contact);
    }
}
