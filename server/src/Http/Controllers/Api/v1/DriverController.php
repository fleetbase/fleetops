<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Events\DriverLocationChanged;
use Fleetbase\FleetOps\Events\GeofenceEntered;
use Fleetbase\FleetOps\Events\GeofenceExited;
use Fleetbase\FleetOps\Events\VehicleLocationChanged;
use Fleetbase\FleetOps\Http\Requests\CreateDriverRequest;
use Fleetbase\FleetOps\Http\Requests\DriverSimulationRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateDriverRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Driver as DriverResource;
use Fleetbase\FleetOps\Jobs\CheckGeofenceDwell;
use Fleetbase\FleetOps\Jobs\SimulateDrivingRoute;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\GeofenceIntersectionService;
use Fleetbase\FleetOps\Support\OSRM;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\SwitchOrganizationRequest;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Models\UserDevice;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Support\Auth;
use Geocoder\Laravel\Facades\Geocoder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DriverController extends Controller
{
    use \Fleetbase\FleetOps\Http\Controllers\Concerns\ResolvesReviewAccountBypass;

    /**
     * Creates a new Fleetbase Driver resource.
     *
     * @return \Fleetbase\Http\Resources\Driver
     */
    public function create(CreateDriverRequest $request)
    {
        // get request input
        $input = $request->except(['name', 'password', 'email', 'phone', 'location', 'altitude', 'heading', 'speed', 'meta']);

        // Add default status
        $input['status'] = $request->input('status', 'available');

        // get user details for driver
        $userDetails                 = $request->only(['name', 'password', 'email', 'phone', 'timezone']);

        // Get current company session
        $company = $request->has('company') ? $this->companyFromRequest($request) : $this->currentCompany();

        // Debugging: Ensure company is retrieved correctly
        if (!$company) {
            return $this->apiError('Company not found.');
        }

        // Apply user infos
        $userDetails = $this->applyUserInfoFromRequest($request, $userDetails);

        // Set company_uuid before creating user
        $userDetails['company_uuid'] = $company->uuid;

        // create user account for driver
        $user = $this->createUser($userDetails);

        // Assign company — the early return above guarantees $company is set
        $user->assignCompany($company);

        // Set user type
        $user->setUserType('driver');

        // assign driver role
        $user->assignSingleRole('Driver');

        // set user id
        $input['user_uuid']    = $user->uuid;
        $input['company_uuid'] = $company->uuid;  // Ensure correct company_uuid is set

        // vehicle assignment public_id -> uuid
        if ($request->has('vehicle')) {
            $input['vehicle_uuid'] = $this->getUuid('vehicles', [
                'public_id'    => $request->input('vehicle'),
                'company_uuid' => $company->uuid,  // Use $company->uuid instead of session
            ]);
        }

        // vendor assignment public_id -> uuid
        if ($request->has('vendor')) {
            $input['vendor_uuid'] = $this->getUuid('vendors', [
                'public_id'    => $request->input('vendor'),
                'company_uuid' => $company->uuid,  // Use $company->uuid instead of session
            ]);
        }

        // order|alias:job assignment public_id -> uuid
        if ($request->has('job')) {
            $input['current_job_uuid'] = $this->getUuid('orders', [
                'public_id'    => $request->input('job'),
                'company_uuid' => $company->uuid,  // Use $company->uuid instead of session
            ]);
        }

        // set default online
        if (!isset($input['online'])) {
            $input['online'] = 0;
        }

        // latitude / longitude
        if ($request->has(['latitude', 'longitude'])) {
            $input['location'] = $this->pointFromCoordinates($request->only(['latitude', 'longitude']));
        }

        // create the driver
        $driver = $this->createDriver($input);

        // Handle photo upload using FileResolverService
        if ($request->has('photo')) {
            $path = 'uploads/' . $company->uuid . '/drivers';
            $file = $this->resolveFile($request->input('photo'), $path);

            if ($file) {
                $user->update(['photo_uuid' => $file->uuid]);
            }
        }

        // load user
        $driver = $driver->load(['user', 'vehicle', 'vendor', 'currentJob']);

        // response the driver resource
        return $this->driverResource($driver);
    }

    /**
     * Updates a Fleetbase Driver resource.
     *
     * @param string                                       $id
     * @param \Fleetbase\Http\Requests\UpdateDriverRequest $request
     *
     * @return \Fleetbase\Http\Resources\Driver
     */
    public function update($id, UpdateDriverRequest $request)
    {
        // find for the driver
        try {
            $driver = $this->findDriver($id, ['user']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->except(['name', 'password', 'email', 'phone', 'location', 'altitude', 'heading', 'speed', 'meta']);

        /*
         * Deliberately no `password` here. Setting one through a general update
         * meant anyone holding a driver's token — or an unlocked handset —
         * could take the account without proving they knew the existing
         * password. Changing a password is its own operation with its own
         * proof: see changePassword(), forgotPassword() and resetPassword().
         */
        $userDetails = $request->only(['name', 'email', 'phone']);

        // update driver user details
        $driverUser = $driver->getUser();
        if ($driverUser) {
            $driverUser->update($userDetails);
        }

        // vehicle assignment public_id -> uuid
        if ($request->has('vehicle')) {
            $input['vehicle_uuid'] = $this->getUuid('vehicles', [
                'public_id'    => $request->input('vehicle'),
                'company_uuid' => $this->sessionCompany(),
            ]);
        }

        // vendor assignment public_id -> uuid
        if ($request->has('vendor')) {
            $input['vendor_uuid'] = $this->getUuid('vendors', [
                'public_id'    => $request->input('vendor'),
                'company_uuid' => $this->sessionCompany(),
            ]);
        }

        // order|alias:job assignment public_id -> uuid
        if ($request->has('job')) {
            $input['current_job_uuid'] = $this->getUuid('orders', [
                'public_id'    => $request->input('job'),
                'company_uuid' => $this->sessionCompany(),
            ]);
        }

        // latitude / longitude
        if ($request->has(['latitude', 'longitude'])) {
            $input['location'] = $this->pointFromCoordinates($request->only(['latitude', 'longitude']));
        }

        // create the driver
        $driver->update($input);
        $driver->flushAttributesCache();

        // Handle photo upload using FileResolverService
        if ($request->has('photo')) {
            $path = 'uploads/' . $this->sessionCompany() . '/drivers';
            $file = $this->resolveFile($request->input('photo'), $path);

            if ($file) {
                $driver->user->update(['photo_uuid' => $file->uuid]);
            }
        }

        // load user
        $driver = $driver->load(['user', 'vehicle', 'vendor', 'currentJob']);

        // response the driver resource
        return $this->driverResource($driver);
    }

    /**
     * Query for Fleetbase Driver resources.
     *
     * @return \Fleetbase\Http\Resources\DriverCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryDrivers($request);

        return $this->driverResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase Driver resources.
     *
     * @param string $id
     *
     * @return \Fleetbase\Http\Resources\DriverCollection
     */
    public function find($id)
    {
        // find for the driver
        try {
            $driver = $this->findDriver($id, ['user', 'vehicle', 'vendor', 'currentJob']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // response the driver resource
        return $this->driverResource($driver);
    }

    /**
     * Deletes a Fleetbase Driver resources.
     *
     * @param string $id
     *
     * @return \Fleetbase\Http\Resources\DriverCollection
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $driver = $this->findDriver($id, ['user', 'vehicle', 'vendor', 'currentJob']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // delete the driver
        $driver->delete();

        // response the driver resource
        return $this->deletedDriverResource($driver);
    }

    /**
     * Update drivers geolocation data.
     *
     * @return \Illuminate\Http\Response
     */
    public function track(string $id, Request $request)
    {
        $latitude  = (float) $request->input('latitude');
        $longitude = (float) $request->input('longitude');
        $altitude  = $request->input('altitude');
        $heading   = $request->input('heading');
        $speed     = $request->input('speed');

        try {
            $driver = $this->findDriver($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->apiError('Driver resource not found.', 404);
        }

        // If no lat/lng provided, maintain compatibility and just return existing driver resource
        if (empty($latitude) && empty($longitude)) {
            return $this->driverResource($driver);
        }

        $isGeocodable = Carbon::parse($driver->updated_at)->diffInMinutes(Carbon::now(), false) > 10 || empty($driver->country) || empty($driver->city);

        $positionData = [
            'location'  => new Point($latitude, $longitude),
            'latitude'  => $latitude,
            'longitude' => $longitude,
            'altitude'  => $altitude,
            'heading'   => $heading,
            'speed'     => $speed,
        ];

        if ($order = $driver->getCurrentOrder()) {
            $positionData['order_uuid'] = $order->uuid;
            $destination                = $order->payload?->getPickupOrCurrentWaypoint();

            if ($destination) {
                $positionData['destination_uuid'] = $destination->uuid;
            }
        }

        $driver->updateQuietly($positionData);
        $driver->createPosition($positionData);

        $driver->loadMissing('vehicle');
        if ($vehicle = $driver->vehicle) {
            $vehicleUpdateData = [...$positionData];
            if ($vehicle->online !== $driver->online) {
                $vehicleUpdateData['online'] = $driver->online;
            }
            $vehicle->updateQuietly($vehicleUpdateData);
            $vehicle->createPosition($positionData);
            broadcast(new VehicleLocationChanged($vehicle, ['driver' => $driver->public_id]));
        }

        if ($isGeocodable) {
            try {
                $geocoded = Geocoder::reverse($latitude, $longitude)->get()->first();
                if ($geocoded) {
                    $driver->updateQuietly([
                        'city'    => $geocoded->getLocality(),
                        'country' => $geocoded->getCountry()->getCode(),
                    ]);
                }
            } catch (\Throwable $e) {
                if (app()->bound('sentry')) {
                    app('sentry')->captureException($e);
                }
            }
        }

        broadcast(new DriverLocationChanged($driver));

        // ----------------------------------------------------------------
        // Geofence intersection detection
        //
        // After broadcasting the location change, run the geofence engine
        // asynchronously. We catch all exceptions so that a geofence error
        // never prevents the location update response from being returned.
        // ----------------------------------------------------------------
        try {
            $newLocation     = new Point($latitude, $longitude);
            $geofenceService = app(GeofenceIntersectionService::class);
            $this->processSubjectGeofenceCrossings($driver, $newLocation, 'driver_geofence_states', 'driver_uuid', $geofenceService->detectDriverCrossings($driver, $newLocation));

            if ($vehicle) {
                $vehicle->loadMissing('driver');
                $this->processSubjectGeofenceCrossings($vehicle, $newLocation, 'vehicle_geofence_states', 'vehicle_uuid', $geofenceService->detectVehicleCrossings($vehicle, $newLocation));
            }
        } catch (\Throwable $geofenceException) {
            // Log the error but never let geofence processing block the response
            if (app()->bound('sentry')) {
                app('sentry')->captureException($geofenceException);
            }
        }

        return $this->driverResource($driver);
    }

    /**
     * Update a driver's "online" status based on the incoming request.
     *
     * If the request includes an "online" parameter, its value is cast to a boolean and applied.
     * If not, the existing "online" status is toggled (true -> false, false -> true).
     * A JSON 404 response is returned if the specified driver does not exist.
     *
     * @param string  $id      the unique identifier of the driver resource
     * @param Request $request the incoming HTTP request
     *
     * @return \Illuminate\Http\JsonResponse|\App\Http\Resources\DriverResource
     */
    public function toggleOnline(string $id, Request $request)
    {
        try {
            $driver = $this->findDriver($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse([
                'error' => 'Driver resource not found.',
            ], 404);
        }

        // Retrieve the "online" parameter from the request if provided
        $onlineParam = $request->input('online');

        // Determine the final boolean value for "online"
        $onlineValue = is_null($onlineParam) ? !$driver->online : Utils::castBoolean($onlineParam);

        // Perform a single update call
        $driver->updateQuietly(['online' => $onlineValue]);

        // Update vehicle online too
        $driver->loadMissing('vehicle');
        if ($vehicle = $driver->vehicle) {
            $vehicle->updateQuietly(['online' => $onlineValue]);
        }

        // Return the updated resource
        return $this->driverResource($driver);
    }

    /**
     * Register device to the driver.
     *
     * @return \Illuminate\Http\Response
     */
    public function registerDevice(?string $id = null, ?Request $request = null)
    {
        // Laravel does NOT inject a class-typed parameter that declares a default value —
        // RouteDependencyResolverTrait skips it and the default (null) is used. So the
        // router always called this with $request === null, which made
        // POST /v1/drivers/{id}/register-device fail with "Call to a member function
        // input() on null" and POST /v1/drivers/register-device answer 404 (the driver was
        // looked up by a null user_uuid). The default has to stay, because the internal
        // controller delegates to this method with an id only.
        $request = $request ?? request();

        try {
            // With an id (…/{id}/register-device) look the driver up directly; without
            // one (…/register-device and the internal delegation) resolve the driver
            // from the authenticated user.
            $driver = $id ? $this->findDriver($id) : $this->currentDriver($request);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        $token    = $request->input('token');
        $platform = $request->or(['platform', 'os']);

        if (!$token) {
            return $this->apiError('Token is required to register device.');
        }

        if (!$platform) {
            return $this->apiError('Platform is required to register device.');
        }

        $device = $this->firstOrCreateUserDevice(
            [
                'token'    => $token,
                'platform' => $platform,
            ],
            [
                'user_uuid' => $driver->user_uuid,
                'platform'  => $platform,
                'token'     => $token,
                'status'    => 'active',
            ]
        );

        return $this->jsonResponse([
            'device' => $device->public_id,
        ], 200);
    }

    /**
     * Authenticates customer using login credentials and returns with auth token.
     *
     * @return DriverResource
     */
    public function login(Request $request)
    {
        $identity = $request->input('identity');
        $password = $request->input('password');
        // $attrs    = $request->input(['name', 'phone', 'email']);

        // Get driver attempting to authenticate via phone
        $user = User::where(
            function ($query) use ($identity) {
                $query->where('phone', static::phone($identity));
                $query->orWhere('email', $identity);
            }
        )->whereHas('driver')->first();

        // Check password to authenticate driver
        if (!Hash::check($password, $user->password)) {
            return response()->apiError('Authentication failed using password provided.', 401);
        }

        // Get the user's company for this driver profile
        $company = static::getDriverCompanyFromUser($user);

        // Get driver record
        $driver = Driver::where(
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $company->uuid,
            ]
        )->first();

        // generate auth token
        try {
            $token = $user->createToken($driver->uuid);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        $driver->token = $token->plainTextToken;

        return new DriverResource($driver);
    }

    /**
     * Attempts authentication with phone number via SMS verification.
     *
     * @return \Illuminate\Http\Response
     */
    public function loginWithPhone()
    {
        $phone = static::phone();

        // check if user exists
        $user = User::where('phone', $phone)->whereHas('driver')->whereNull('deleted_at')->first();
        if (!$user) {
            return response()->apiError('No driver with this phone # found.');
        }

        // Get the user's company for this driver profile
        $company = static::getDriverCompanyFromUser($user);

        // generate verification token
        try {
            VerificationCode::generateSmsVerificationFor($user, 'driver_login', [
                'company_uuid'    => $company->uuid,
                'messageCallback' => function ($verification) use ($company) {
                    return 'Your ' . data_get($company, 'name', config('app.name')) . ' verification code is ' . $verification->code;
                },
            ]);

            return response()->json(['status' => 'OK', 'method' => 'sms']);
        } catch (\Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }

            // SEND VERIFICATION CODE BY EMAIL IF DRIVER HAS EMAIL ADDRESS
            if ($user->email) {
                try {
                    VerificationCode::generateEmailVerificationFor($user, 'driver_login', [
                        'company_uuid'    => $company->uuid,
                        'messageCallback' => function ($verification) use ($company) {
                            return 'Your ' . data_get($company, 'name', config('app.name')) . ' verification code is ' . $verification->code;
                        },
                    ]);

                    return response()->json(['status' => 'OK', 'method' => 'email']);
                } catch (\Throwable $e) {
                    if (app()->bound('sentry')) {
                        app('sentry')->captureException($e);
                    }
                }
            }
        }

        return response()->apiError('Unable to send SMS Verification code.');
    }

    /**
     * Verifys SMS code and sends auth token with customer resource.
     *
     * @return DriverResource
     */
    public function verifyCode(Request $request)
    {
        $identity = Utils::isEmail($request->identity) ? $request->identity : static::phone($request->identity);
        $code     = $request->input('code');
        $for      = $request->input('for', 'driver_login');
        $attrs    = $request->input(['name', 'phone', 'email']);

        if ($for === 'create_driver') {
            return $this->create($request);
        }

        // check if user exists
        $user = User::whereHas('driver')->where(function ($query) use ($identity) {
            $query->where('phone', $identity);
            $query->orWhere('email', $identity);
        })->first();

        if (!$user) {
            return response()->apiError('Unable to verify code.');
        }

        // find and verify code
        $verificationCode = VerificationCode::where(['subject_uuid' => $user->uuid, 'code' => $code, 'for' => $for])->exists();
        if (!$verificationCode && !static::verificationBypassMatches($identity, $code)) {
            return response()->apiError('Invalid verification code!');
        }

        // Get the user's company for this driver profile
        $company = static::getDriverCompanyFromUser($user);

        // get driver record
        $driver = Driver::where(
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $company->uuid,
            ]
        )->first();

        // generate auth token
        try {
            $token = $user->createToken($driver->uuid);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        try {
            DB::table('drivers')->where('uuid', $driver->uuid)->update(['auth_token' => $token->plainTextToken]);
            $driver->token = $token->plainTextToken;
        } catch (\Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }

            return response()->apiError('Unable to authenticate driver.');
        }

        return new DriverResource($driver);
    }

    /**
     * Gets the current organization/company for the driver.
     *
     * @return Organization
     */
    public function currentOrganization(string $id, Request $request)
    {
        try {
            $driver = Driver::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->apiError('Driver resource not found.', 404);
        }

        // Get the driver user account
        //
        // Unreachable: DriverScope adds a whereHas('user') to every driver
        // query, so findRecordOrFail() never yields a driver whose user is
        // missing. Kept as defence in depth if that scope is ever relaxed.
        $user = $driver->getUser();
        // @codeCoverageIgnoreStart
        if (!$user) {
            return response()->apiError('Driver has not user account.');
        }
        // @codeCoverageIgnoreEnd

        // Get the user account company
        $company = Auth::getCompanySessionForUser($user);
        if (!$company) {
            return response()->apiError('No company found for this driver.');
        }

        return new Organization($company);
    }

    /**
     * List organizations that driver is apart of.
     *
     * @return Organization
     */
    public function listOrganizations(string $id, Request $request)
    {
        try {
            $driver = Driver::findRecordOrFail($id, ['user.companies']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        $companies = Company::whereHas('drivers', function ($driverQuery) use ($driver) {
            $driverQuery->where('user_uuid', $driver->user_uuid);
        })->get();

        return Organization::collection($companies);
    }

    /**
     * Allow driver to switch organization.
     *
     * @return Organization
     */
    public function switchOrganization(string $id, SwitchOrganizationRequest $request)
    {
        $nextOrganization = $request->input('next');

        try {
            $driver = Driver::findRecordOrFail($id, ['user']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // Get the next organization
        $company = Company::where('public_id', $nextOrganization)->first();

        if ($company->uuid === $driver->user->company_uuid) {
            return response()->apiError('Driver is already on this organizations session.');
        }

        if (!CompanyUser::where(['user_uuid' => $driver->user_uuid, 'company_uuid' => $company->uuid])->exists()) {
            return response()->apiError('Driver does not belong to this organization.');
        }

        // Get the driver user account
        //
        // Unreachable: the same user was already dereferenced above to compare
        // company sessions, and DriverScope guarantees it exists in the first
        // place. Kept as defence in depth if that scope is ever relaxed.
        $user = $driver->getUser();
        // @codeCoverageIgnoreStart
        if (!$user) {
            return response()->apiError('Critial error, driver has not user account.');
        }
        // @codeCoverageIgnoreEnd

        // Get the users driver profile for this company
        $driverProfile = Driver::where(['user_uuid' => $user->uuid, 'company_uuid' => $company->uuid])->first();
        if (!$driverProfile) {
            return response()->apiError('User does not have a driver profile with this organization.');
        }

        // Assign user to company and update their session
        $user->update(['company_uuid' => $company->uuid]);
        Auth::setSession($user);

        // Authenticate new driver
        try {
            $token = $user->createToken($driverProfile->uuid);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        $driverProfile->token = $token->plainTextToken;

        return ['organization' => new Organization($company), 'driver' => new DriverResource($driverProfile)];
    }

    /**
     * This route can help to simulate certain actions for a driver.
     *      Actions:
     *          - Drive
     *          - Order.
     *
     * @param \Fleetbase\Http\Requests\DriverSimulationRequest $request
     *
     * @return \Illuminate\Http\Response
     */
    public function simulate(string $id, DriverSimulationRequest $request)
    {
        $start = $request->input('start');
        $end   = $request->input('end');
        $order = $request->input('order');

        try {
            /** @var Driver $driver */
            $driver = Driver::findRecordOrFail($id, ['user']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        if ($order) {
            try {
                /** @var Order $order */
                $order = Order::findRecordOrFail($order, ['payload']);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                return response()->json(
                    [
                        'error' => 'Order resource not found.',
                    ],
                    404
                );
            }

            return $this->simulateDrivingForOrder($driver, $order);
        }

        return $this->simulateDrivingForRoute($driver, $start, $end);
    }

    /**
     * Simulates a driving route for a given driver between a start and end point.
     *
     * @param Driver $driver the driver for whom the route is being simulated
     * @param mixed  $start  the starting point of the route, can be a Point object or other representation
     * @param mixed  $end    the ending point of the route, can be a Point object or other representation
     *
     * @return \Illuminate\Http\JsonResponse the response containing the route information
     *
     * @throws \Exception if there is an error in resolving the points or interacting with the OSRM API
     */
    public function simulateDrivingForRoute(Driver $driver, $start, $end)
    {
        // Resolve Point's from start/end
        $start = Utils::getPointFromMixed($start);
        $end   = Utils::getPointFromMixed($end);

        // Send points to OSRM
        $route = OSRM::getRoute($start, $end);

        // Create simulation events
        if (isset($route['code']) && $route['code'] === 'Ok') {
            // Get the route geometry to decode
            $routeGeometry = data_get($route, 'routes.0.geometry');

            // Decode the waypoints if needed
            $waypoints = OSRM::decodePolyline($routeGeometry);

            // Dispatch the job for each waypoint
            SimulateDrivingRoute::dispatchIf(Arr::first($waypoints) instanceof Point, $driver, $waypoints);
        }

        return response()->json($route);
    }

    /**
     * Simulates a driving route for a given driver based on an order's pickup and dropoff waypoints.
     *
     * @param Driver $driver the driver for whom the route is being simulated
     * @param Order  $order  the order containing the pickup and dropoff waypoints
     *
     * @return \Illuminate\Http\JsonResponse the response containing the route information
     *
     * @throws \Exception if there is an error in resolving the points, validating the waypoints, or interacting with the OSRM API
     */
    public function simulateDrivingForOrder(Driver $driver, Order $order)
    {
        // Get the order Pickup and Dropoff Waypoints
        $pickup  = $order->payload->getPickupOrFirstWaypoint();
        $dropoff = $order->payload->getDropoffOrLastWaypoint();

        // Convert order Pickup/Dropoff Place Waypoint's to Point's
        $start = Utils::getPointFromMixed($pickup);
        $end   = Utils::getPointFromMixed($dropoff);

        // Send points to OSRM
        $route = OSRM::getRoute($start, $end);

        // Create simulation events
        if (isset($route['code']) && $route['code'] === 'Ok') {
            // Get the route geometry to decode
            $routeGeometry = data_get($route, 'routes.0.geometry');

            // Decode the waypoints if needed
            $waypoints = OSRM::decodePolyline($routeGeometry);

            // Loop through waypoints to calculate the heading for each point
            for ($i = 0; $i < count($waypoints) - 1; $i++) {
                $point1 = $waypoints[$i];
                $point2 = $waypoints[$i + 1];

                $heading = Utils::calculateHeading($point1, $point2);

                // Directly add the 'heading' property to the Point object
                $point1->heading = $heading;
            }

            // Dispatch the job for each waypoint
            SimulateDrivingRoute::dispatchIf(Arr::first($waypoints) instanceof Point, $driver, $waypoints);
        }

        return response()->json($route);
    }

    protected function companyFromRequest(Request $request): ?Company
    {
        return Auth::getCompanyFromRequest($request);
    }

    protected function currentCompany(): ?Company
    {
        return Auth::getCompany();
    }

    protected function sessionCompany(): ?string
    {
        return session('company');
    }

    protected function applyUserInfoFromRequest(Request $request, array $userDetails): array
    {
        return User::applyUserInfoFromRequest($request, $userDetails);
    }

    protected function createUser(array $userDetails): User
    {
        /*
         * `password` is guarded on User, so mass assignment drops it without a
         * word. The create endpoint has always accepted and validated one, so a
         * driver created through the API could never sign in with the password
         * their operator chose for them. Set it after the fact, where the
         * model's mutator hashes it.
         */
        $password = $userDetails['password'] ?? null;
        unset($userDetails['password']);

        $user = User::create($userDetails);

        if (is_string($password) && strlen($password)) {
            $user->password = $password;
            $user->save();
        }

        return $user;
    }

    protected function getUuid(array|string $table, array $where, array $options = []): mixed
    {
        return Utils::getUuid($table, $where, $options);
    }

    protected function pointFromCoordinates(array $coordinates): Point
    {
        return Utils::getPointFromCoordinates($coordinates);
    }

    protected function createDriver(array $attributes): Driver
    {
        return Driver::create($attributes);
    }

    protected function resolveFile(mixed $input, string $path): mixed
    {
        return app(\Fleetbase\Services\FileResolverService::class)->resolve($input, $path);
    }

    protected function findDriver(string $id, array $with = []): Driver
    {
        return Driver::findRecordOrFail($id, $with);
    }

    /**
     * Resolve the driver for the authenticated request (used when no explicit
     * driver id is supplied, e.g. a driver-authenticated session).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    protected function currentDriver(?Request $request): Driver
    {
        // $request->user() is never populated on the public API: the `fleetbase.api`
        // middleware calls Auth::setSession($apiCredential), which writes the session keys
        // but leaves $login false, so no user resolver is ever bound. Reading it alone
        // meant POST /v1/drivers/register-device looked a driver up by a null user_uuid
        // and answered 404 for every consumer of the route.
        //
        // Auth::getUserFromSession() encodes this same order, but it also calls auth() and
        // session()->has(), neither of which exists in the SQLite test harness — so the
        // two sources are read directly here.
        $user = optional($request)->user();
        if (!$user instanceof User) {
            $user = User::where('uuid', session('user'))->first();
        }

        return Driver::where('user_uuid', optional($user)->uuid)->firstOrFail();
    }

    protected function queryDrivers(Request $request)
    {
        return Driver::queryWithRequest(
            $request,
            function (&$query, $request) {
                if ($request->has('vendor')) {
                    $query->whereHas('vendor', function ($q) use ($request) {
                        $q->where('public_id', $request->input('vendor'));
                    });
                }
            }
        );
    }

    protected function firstOrCreateUserDevice(array $attributes, array $values): UserDevice
    {
        return UserDevice::firstOrCreate($attributes, $values);
    }

    protected function driverResource(Driver $driver)
    {
        return new DriverResource($driver);
    }

    protected function driverResourceCollection($results)
    {
        return DriverResource::collection($results);
    }

    protected function deletedDriverResource(Driver $driver)
    {
        return new DeletedResource($driver);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }

    protected function apiError(string $message, int $status = 400)
    {
        return response()->apiError($message, $status);
    }

    /**
     * Get the drivers current company using their user account.
     */
    private static function getDriverCompanyFromUser(User $user): ?Company
    {
        // company defaults to null
        $company = null;

        // Load the driver profile to get the company
        $driverProfiles = Driver::where('user_uuid', $user->uuid)->get();
        if ($driverProfiles->count() > 0) {
            // Get the driver profile matching user current company session
            $currentDriverProfile = $driverProfiles->first(function ($driverProfile) use ($user) {
                return $user->company_uuid === $driverProfile->company_uuid;
            });
            $driverProfile = $currentDriverProfile ? $currentDriverProfile : $driverProfiles->first();
            // get company from driver profile
            $company = Company::where('uuid', $driverProfile->company_uuid)->first();
        }

        // If unable to find company from driver profile, fallback to session flow
        if (!$company) {
            $company = Auth::getCompanySessionForUser($user);
        }

        return $company;
    }

    /**
     * Whether the supplied code matches the configured testing bypass code.
     *
     * Three conditions, all required, mirroring the console equivalent in
     * Fleetbase\Http\Controllers\Internal\v1\AuthController::authenticateWithVerificationCode:
     *
     *  - a bypass code must actually be configured. The previous
     *    `$code !== config(...)` comparison meant that on a default install --
     *    where the config resolves to null -- omitting `code` entirely made the
     *    check `null !== null`, i.e. false, so the guard never fired and any
     *    caller with a valid org API credential could mint a driver token for
     *    any driver in the install without a code at all;
     *  - the app must not be in production, so a code left set in a deployed
     *    .env cannot be used against a live fleet;
     *  - the comparison is constant-time.
     *
     * Shared with Internal\v1\DriverController so both verify-code paths cannot
     * drift apart.
     */
    public static function verificationBypassMatches(?string $identity, ?string $code): bool
    {
        return static::reviewAccountBypassMatches(
            'fleetops.navigator.bypass_verification_code',
            'fleetops.navigator.review_accounts',
            $identity,
            $code,
            'navigator'
        );
    }

    /**
     * Patches phone number with international code.
     */
    private static function phone(?string $phone = null): string
    {
        if ($phone === null) {
            $phone = request()->input('phone');
        }

        if (!Str::startsWith($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    private function processSubjectGeofenceCrossings(Driver|Vehicle $subject, Point $newLocation, string $stateTable, string $subjectColumn, array $crossings): void
    {
        foreach ($crossings as $crossing) {
            $geofence     = $crossing['geofence'];
            $geofenceType = $crossing['geofence_type'];

            if ($crossing['type'] === 'entered') {
                if (!$geofence->trigger_on_entry && empty($geofence->dwell_threshold_minutes)) {
                    continue;
                }

                DB::table($stateTable)->upsert(
                    [
                        $subjectColumn   => $subject->uuid,
                        'geofence_uuid'  => $geofence->uuid,
                        'geofence_type'  => $geofenceType,
                        'is_inside'      => true,
                        'entered_at'     => now(),
                        'exited_at'      => null,
                        'dwell_job_id'   => null,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ],
                    [$subjectColumn, 'geofence_uuid'],
                    ['is_inside', 'entered_at', 'exited_at', 'dwell_job_id', 'updated_at']
                );

                if ($geofence->trigger_on_entry) {
                    event(new GeofenceEntered($subject, $geofence, $geofenceType, $newLocation));
                }

                if ($geofence->dwell_threshold_minutes > 0) {
                    $dwellJob = CheckGeofenceDwell::dispatch(
                        $subject->uuid,
                        $geofence->uuid,
                        $geofenceType,
                        $subjectColumn === 'vehicle_uuid' ? 'vehicle' : 'driver'
                    )->delay(now()->addMinutes($geofence->dwell_threshold_minutes));

                    DB::table($stateTable)
                        ->where($subjectColumn, $subject->uuid)
                        ->where('geofence_uuid', $geofence->uuid)
                        ->update(['dwell_job_id' => (string) $dwellJob]);
                }
            } elseif ($crossing['type'] === 'exited') {
                $state = DB::table($stateTable)
                    ->where($subjectColumn, $subject->uuid)
                    ->where('geofence_uuid', $geofence->uuid)
                    ->first();

                $dwellMinutes = null;
                if ($state && $state->entered_at) {
                    $dwellMinutes = (int) Carbon::parse($state->entered_at)->diffInMinutes(now());
                }

                DB::table($stateTable)
                    ->where($subjectColumn, $subject->uuid)
                    ->where('geofence_uuid', $geofence->uuid)
                    ->update([
                        'is_inside'    => false,
                        'exited_at'    => now(),
                        'dwell_job_id' => null,
                        'updated_at'   => now(),
                    ]);

                if ($geofence->trigger_on_exit) {
                    event(new GeofenceExited($subject, $geofence, $geofenceType, $newLocation, $dwellMinutes));
                }
            }
        }
    }

    /**
     * Change the authenticated driver's password, proving the current one.
     *
     * A password change is an authorisation decision, not an attribute update.
     * Requiring the existing password is what stops a borrowed handset or a
     * leaked token from becoming a permanent account takeover, and re-issuing
     * the caller's token afterwards is what stops every *other* session from
     * outliving the change.
     */
    public function changePassword(Request $request, string $id)
    {
        $current  = $request->input('current_password');
        $password = $request->input('password');

        if (!$current || !$password) {
            return response()->apiError('current_password and password are required.', 400);
        }

        if (strlen($password) < 8) {
            return response()->apiError('Password must be at least 8 characters.', 400);
        }

        if ($request->filled('password_confirmation') && $request->input('password_confirmation') !== $password) {
            return response()->apiError('Password confirmation does not match.', 422);
        }

        try {
            $driver = $this->findDriver($id, ['user']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->apiError('Driver resource not found.', 404);
        }

        $user = static::findDriverUserRecord($driver);
        if (!$user) {
            return response()->apiError('Driver has no user account.', 422);
        }

        if (!static::passwordMatches($user, $current)) {
            // Same wording whether the password was wrong or the account is odd
            // — a change endpoint should not become an oracle.
            return response()->apiError('The current password is incorrect.', 422);
        }

        $user->password = $password;
        $user->save();

        /*
         * Every other session dies with the old password. The caller keeps
         * working by being handed a fresh token in the same response, so a
         * driver who changes their password mid-shift is not signed out of the
         * device in their hand.
         */
        $user->tokens()->delete();
        $token = $user->createToken($request->input('device_name', 'navigator'));

        return response()->json([
            'status' => 'ok',
            'token'  => $token->plainTextToken,
        ]);
    }

    /**
     * Send a password reset code to a driver who cannot sign in.
     *
     * Answers identically whether or not the identity exists: a reset endpoint
     * that 404s on an unknown phone number is a way to enumerate a company's
     * drivers.
     */
    public function forgotPassword(Request $request)
    {
        $identity = $request->input('identity');
        if (!$identity) {
            return response()->apiError('Identity is required.', 400);
        }

        $user = static::findDriverUserByIdentity($identity);
        if (!$user) {
            return response()->json(['status' => 'ok']);
        }

        try {
            static::sendResetCode($user, $identity);
        } catch (\Throwable $e) {
            return response()->apiError(static::debugEnabled() ? $e->getMessage() : 'Unable to send reset code.');
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Set a new password using a code sent by forgotPassword().
     */
    public function resetPassword(Request $request)
    {
        $identity = $request->input('identity');
        $code     = $request->input('code');
        $password = $request->input('password');

        if (!$identity || !$code || !$password) {
            return response()->apiError('identity, code, and password are required.', 400);
        }

        if (strlen($password) < 8) {
            return response()->apiError('Password must be at least 8 characters.', 400);
        }

        $user = static::findDriverUserByIdentity($identity);
        if (!$user) {
            return response()->apiError('Invalid or expired reset code.', 422);
        }

        $verification = static::findResetCode($user, $code);

        if (!$verification) {
            // One message for a wrong code, an expired code and an unknown
            // identity alike.
            return response()->apiError('Invalid or expired reset code.', 422);
        }

        $user->password = $password;
        $user->save();

        $verification->delete();
        $user->tokens()->delete();

        return response()->json(['status' => 'ok']);
    }

    /** Whether the application is in debug mode, so an error may be echoed. */
    protected static function debugEnabled(): bool
    {
        return app()->hasDebugModeEnabled();
    }

    /**
     * Load a driver's user account with every column.
     *
     * The `user` relation selects a named subset of columns, and `password` is
     * not among them — reading it through the relation yields an empty string,
     * so a password comparison would fail for everyone regardless of what they
     * typed. Anything checking a password has to load the record itself.
     */
    protected static function findDriverUserRecord(Driver $driver): ?User
    {
        if (!$driver->user_uuid) {
            return null;
        }

        return User::where('uuid', $driver->user_uuid)->first();
    }

    /** Whether a plaintext password matches the stored hash. */
    protected static function passwordMatches(User $user, string $plain): bool
    {
        return Hash::check($plain, $user->password);
    }

    /** The unexpired reset code for this user, if the one supplied matches. */
    protected static function findResetCode(User $user, string $code)
    {
        return VerificationCode::where([
            'subject_uuid' => $user->uuid,
            'code'         => $code,
            'for'          => 'driver_password_reset',
        ])->where('expires_at', '>', now())->first();
    }

    /** Sends the reset code by whichever channel the identity names. */
    protected static function sendResetCode(User $user, string $identity): void
    {
        // if/else rather than an early return: a `return` sitting after a call
        // that can throw is a line no test can reach.
        if (Utils::isEmail($identity)) {
            VerificationCode::generateEmailVerificationFor($user, 'driver_password_reset', [
                'subject'         => config('app.name') . ' password reset',
                'messageCallback' => fn ($v) => 'Your ' . config('app.name') . ' password reset code is ' . $v->code,
            ]);
        } else {
            VerificationCode::generateSmsVerificationFor($user, 'driver_password_reset', [
                'messageCallback' => fn ($v) => 'Your ' . config('app.name') . ' password reset code is ' . $v->code,
            ]);
        }
    }

    /** Resolve a driver's user account from an email address or a phone number. */
    protected static function findDriverUserByIdentity(string $identity): ?User
    {
        $isEmail = Utils::isEmail($identity);
        $column  = $isEmail ? 'email' : 'phone';
        $value   = $isEmail ? $identity : static::phone($identity);

        return User::whereHas('driver')->where($column, $value)->first();
    }
}
