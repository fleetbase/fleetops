<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\FleetOps\Exports\DriverExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Requests\Internal\CreateDriverRequest;
use Fleetbase\FleetOps\Http\Requests\Internal\UpdateDriverRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Notifications\UserInvited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class DriverController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'driver';

    /**
     * Creates a record with request payload.
     *
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        $input = $request->input('driver');

        // create validation request
        $createDriverRequest = CreateDriverRequest::createFrom($request);
        $rules               = $createDriverRequest->rules();

        // manually validate request
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            // here if the user exists already
            // within organization: offer to create driver record
            // outside organization: invite to join organization AS DRIVER
            if ($validator->errors()->hasAny(['phone', 'email'])) {
                // get existing user
                $existingUser = null;

                // if values provided for user lookup
                if (!empty($input['phone']) || !empty($input['email'])) {
                    $existingUserQuery = User::query();

                    if (!empty($input['phone']) && is_string($input['phone'])) {
                        $existingUserQuery->orWhere(function ($q) use ($input) {
                            $q->where('phone', $input['phone'])->whereNotNull('phone');
                        });
                    }

                    if (!empty($input['email']) && is_string($input['email'])) {
                        $existingUserQuery->orWhere(function ($q) use ($input) {
                            $q->where('email', $input['email'])->whereNotNull('email');
                        });
                    }

                    $existingUser = $existingUserQuery->first();
                }

                if ($existingUser) {
                    // if exists in organization create driver profile for user
                    $isOrganizationMember = $existingUser->companies()->where('company_uuid', session('company'))->exists();

                    // create driver profile for user
                    $input = collect($input)
                        ->except(['name', 'password', 'email', 'phone', 'meta', 'avatar_uuid', 'photo_uuid'])
                        ->filter()
                        ->toArray();

                    // set the user
                    $input['company_uuid'] = session('company');
                    $input['user_uuid']    = $existingUser->uuid;
                    $input['slug']         = $existingUser->slug;

                    // create the profile
                    $driverProfile = Driver::create($input);

                    if (!$isOrganizationMember) {
                        // send invitation to user
                        $invitation = Invite::create([
                            'company_uuid'    => session('company'),
                            'created_by_uuid' => session('user'),
                            'subject_uuid'    => session('company'),
                            'subject_type'    => Utils::getMutationType('company'),
                            'protocol'        => 'email',
                            'recipients'      => [$existingUser->email],
                            'reason'          => 'join_company',
                        ]);

                        // notify user
                        $existingUser->notify(new UserInvited($invitation));
                    }

                    return response()->json(['driver' => $driverProfile]);
                }
            }

            // check from validator object if phone or email is not unique
            return $createDriverRequest->responseWithErrors($validator);
        }

        try {
            $record = $this->model->createRecordFromRequest(
                $request,
                function (&$request, &$input) {
                    $input = collect($input);

                    $userInput = $input
                        ->only(['name', 'password', 'email', 'phone', 'status', 'avatar_uuid'])
                        ->filter()
                        ->toArray();

                    // handle `photo_uuid`
                    if (isset($input['photo_uuid']) && Str::isUuid($input['photo_uuid'])) {
                        $userInput['avatar_uuid'] = $input['photo_uuid'];
                    }

                    $input = $input
                        ->except(['name', 'password', 'email', 'phone', 'meta', 'avatar_uuid', 'photo_uuid'])
                        ->filter()
                        ->toArray();

                    if (!isset($input['password'])) {
                        $input['password'] = data_get($userInput, 'phone', Str::random(14));
                    }

                    $userInput['company_uuid'] = session('company');
                    $userInput['type']         = 'driver';

                    $user = User::create($userInput);

                    // send invitation to user
                    $invitation = Invite::create([
                        'company_uuid'    => session('company'),
                        'created_by_uuid' => session('user'),
                        'subject_uuid'    => session('company'),
                        'subject_type'    => Utils::getMutationType('company'),
                        'protocol'        => 'email',
                        'recipients'      => [$user->email],
                        'reason'          => 'join_company',
                    ]);

                    // notify user
                    $user->notify(new UserInvited($invitation));

                    $input['user_uuid'] = $user->uuid;
                    $input['slug']      = $user->slug;
                },
                function ($request, &$driver) {
                    $driver->load(['user']);
                }
            );

            return ['driver' => new $this->resource($record)];
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        }
    }

    /**
     * Updates a record with request payload.
     *
     * @return \Illuminate\Http\Response
     */
    public function updateRecord(Request $request, string $id)
    {
        // get input data
        $input = $request->input('driver');

        // create validation request
        $updateDriverRequest = UpdateDriverRequest::createFrom($request);
        $rules               = $updateDriverRequest->rules();

        // manually validate request
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            return $updateDriverRequest->responseWithErrors($validator);
        }

        try {
            $record = $this->model->updateRecordFromRequest(
                $request,
                $id,
                function (&$request, &$driver, &$input) {
                    $driver->load(['user'])->guard(['user_uuid']);
                    $input     = collect($input);
                    $userInput = $input->only(['name', 'password', 'email', 'phone', 'avatar_uuid'])->toArray();
                    // handle `photo_uuid`
                    if (isset($input['photo_uuid']) && Str::isUuid($input['photo_uuid'])) {
                        $userInput['avatar_uuid'] = $input['photo_uuid'];
                    }
                    $input     = $input->except(['name', 'password', 'email', 'phone', 'meta', 'avatar_uuid', 'photo_uuid'])->toArray();

                    $driver->user->update($userInput);
                    $driver->flushAttributesCache();

                    $input['slug'] = $driver->user->slug;
                },
                function ($request, &$driver) {
                    $driver->load(['user']);

                    if ($driver->user) {
                        $driver->user->setHidden(['driver']);
                    }

                    $driver->setHidden(['user']);
                }
            );

            return ['driver' => new $this->resource($record)];
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        }
    }

    /**
     * Get all status options for an driver.
     *
     * @return \Illuminate\Http\Response
     */
    public function statuses()
    {
        $statuses = DB::table('drivers')
            ->select('status')
            ->where('company_uuid', session('company'))
            ->distinct()
            ->get()
            ->pluck('status')
            ->filter()
            ->values();

        return response()->json($statuses);
    }

    /**
     * Export the drivers to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format   = $request->input('format', 'xlsx');
        $fileName = trim(Str::slug('drivers-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new DriverExport(), $fileName);
    }
}
