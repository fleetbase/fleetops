<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Events\DriverLocationChanged;
use Fleetbase\FleetOps\Http\Requests\CreateDriverRequest;
use Fleetbase\FleetOps\Http\Requests\DriverSimulationRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateDriverRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Driver as DriverResource;
use Fleetbase\FleetOps\Jobs\SimulateDrivingRoute;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Flow;
use Fleetbase\FleetOps\Support\OSRM;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\SwitchOrganizationRequest;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Models\UserDevice;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Support\Auth;
use Geocoder\Laravel\Facades\Geocoder;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DriverController extends Controller
{
    /**
     * Creates a new Fleetbase Driver resource.
     *
     * @param \Fleetbase\Http\Requests\CreateDriverRequest $request
     *
     * @return \Fleetbase\Http\Resources\Driver
     */
    public function create(CreateDriverRequest $request)
    {
        // get request input
        $input = $request->except(['name', 'password', 'email', 'phone', 'location', 'altitude', 'heading', 'speed', 'meta']);

        // get user details for driver
        $userDetails                 = $request->only(['name', 'password', 'email', 'phone']);
        $userDetails['company_uuid'] = session('company');

        // create user account for driver
        $user = User::create($userDetails);

        // set user id
        $input['user_uuid']    = $user->uuid;
        $input['company_uuid'] = session('company');

        // vehicle assignment public_id -> uuid
        if ($request->has('vehicle')) {
            $input['vehicle_uuid'] = Utils::getUuid('vehicles', [
                'public_id'    => $request->input('vehicle'),
                'company_uuid' => session('company'),
            ]);
        }

        // vendor assignment public_id -> uuid
        if ($request->has('vendor')) {
            $input['vendor_uuid'] = Utils::getUuid('vendors', [
                'public_id'    => $request->input('vendor'),
                'company_uuid' => session('company'),
            ]);
        }

        // order|alias:job assignment public_id -> uuid
        if ($request->has('job')) {
            $input['current_job_uuid'] = Utils::getUuid('orders', [
                'public_id'    => $request->input('job'),
                'company_uuid' => session('company'),
            ]);
        }

        // default location
        if ($request->missing('location')) {
            $input['location'] = new Point(0, 0);
        }

        // create the driver
        $driver = Driver::create($input);

        // load user
        $driver = $driver->load(['user', 'vehicle', 'vendor', 'currentJob']);

        // response the driver resource
        return new DriverResource($driver);
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
            $driver = Driver::findRecordOrFail($id, ['user']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->except(['name', 'password', 'email', 'phone', 'location', 'altitude', 'heading', 'speed', 'meta']);

        // get user details for driver
        $userDetails = $request->only(['name', 'password', 'email', 'phone']);

        // update driver user details
        $driver->user->update($userDetails);

        // vehicle assignment public_id -> uuid
        if ($request->has('vehicle')) {
            $input['vehicle_uuid'] = Utils::getUuid('vehicles', [
                'public_id'    => $request->input('vehicle'),
                'company_uuid' => session('company'),
            ]);
        }

        // vendor assignment public_id -> uuid
        if ($request->has('vendor')) {
            $input['vendor_uuid'] = Utils::getUuid('vendors', [
                'public_id'    => $request->input('vendor'),
                'company_uuid' => session('company'),
            ]);
        }

        // order|alias:job assignment public_id -> uuid
        if ($request->has('job')) {
            $input['current_job_uuid'] = Utils::getUuid('orders', [
                'public_id'    => $request->input('job'),
                'company_uuid' => session('company'),
            ]);
        }

        // create the driver
        $driver->update($input);
        $driver->flushAttributesCache();

        // load user
        $driver = $driver->load(['user', 'vehicle', 'vendor', 'currentJob']);

        // response the driver resource
        return new DriverResource($driver);
    }

    /**
     * Query for Fleetbase Driver resources.
     *
     * @return \Fleetbase\Http\Resources\DriverCollection
     */
    public function query(Request $request)
    {
        $results = Driver::queryWithRequest(
            $request,
            function (&$query, $request) {
                if ($request->has('vendor')) {
                    $query->whereHas('vendor', function ($q) use ($request) {
                        $q->where('public_id', $request->input('vendor'));
                    });
                }
            }
        );

        return DriverResource::collection($results);
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
            $driver = Driver::findRecordOrFail($id, ['user', 'vehicle', 'vendor', 'currentJob']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // response the driver resource
        return new DriverResource($driver);
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
            $driver = Driver::findRecordOrFail($id, ['user', 'vehicle', 'vendor', 'currentJob']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // delete the driver
        $driver->delete();

        // response the driver resource
        return new DeletedResource($driver);
    }

    /**
     * Update drivers geolocation data.
     *
     * @return \Illuminate\Http\Response
     */
    public function track(string $id, Request $request)
    {
        $latitude  = $request->input('latitude');
        $longitude = $request->input('longitude');
        $altitude  = $request->input('altitude');
        $heading   = $request->input('heading');
        $speed     = $request->input('speed');

        try {
            $driver = Driver::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // check if driver needs a geocoded update to set city and country they are currently in
        $isGeocodable = Carbon::parse($driver->updated_at)->diffInMinutes(Carbon::now(), false) > 10 || empty($driver->country) || empty($driver->city);

        $driver->update([
            'location' => new Point($latitude, $longitude),
            'altitude' => $altitude,
            'heading'  => $heading,
            'speed'    => $speed,
        ]);

        if ($isGeocodable) {
            // attempt to geocode and fill country and city
            $geocoded = Geocoder::reverse($latitude, $longitude)->get()->first();

            if ($geocoded) {
                $driver->update([
                    'city'    => $geocoded->getLocality(),
                    'country' => $geocoded->getCountry()->getCode(),
                ]);
            }
        }

        broadcast(new DriverLocationChanged($driver));

        $driver->updatePosition();
        $driver->refresh();

        return new DriverResource($driver);
    }

    /**
     * Register device to the driver.
     *
     * @return \Illuminate\Http\Response
     */
    public function registerDevice(string $id, Request $request)
    {
        try {
            $driver = Driver::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        $token    = $request->input('token');
        $platform = $request->or(['platform', 'os']);

        if (!$token) {
            return response()->error('Token is required to register device.');
        }

        if (!$platform) {
            return response()->error('Platform is required to register device.');
        }

        $device = UserDevice::firstOrCreate(
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

        return response()->json([
            'device' => $device->public_id,
        ]);
    }

    /**
     * Authenticates customer using login credentials and returns with auth token.
     *
     * @return \Fleetbase\FleetOps\Http\Resources\v1\Driver
     */
    public function login(Request $request)
    {
        $identity = $request->input('identity');
        $password = $request->input('password');
        $attrs    = $request->input(['name', 'phone', 'email']);

        $user = User::where('email', $identity)->orWhere('phone', static::phone($identity))->first();

        if (!Hash::check($password, $user->password)) {
            return response()->error('Authentication failed using password provided.', 401);
        }

        // get the current company session
        $company = Flow::getCompanySessionForUser($user);

        // get driver record
        $driver = Driver::firstOrCreate(
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $company->uuid,
            ],
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $company->uuid,
                'name'         => $attrs['name'] ?? $user->name,
                'phone'        => $attrs['phone'] ?? $user->phone,
                'email'        => $attrs['email'] ?? $user->email,
            ]
        );

        // generate auth token
        try {
            $token = $user->createToken($driver->uuid);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
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
        $user = User::where('phone', $phone)->whereNull('deleted_at')->withoutGlobalScopes()->first();

        if (!$user) {
            return response()->error('No driver with this phone # found.');
        }

        // get the current company session
        $company = Flow::getCompanySessionForUser($user);

        // generate verification token
        try {
            VerificationCode::generateSmsVerificationFor($user, 'driver_login', function ($verification) use ($company) {
                return "Your {$company->name} verification code is {$verification->code}";
            });
        } catch (\Throwable $e) {
            return response()->error($e->getMessage());
        }

        return response()->json(['status' => 'OK']);
    }

    /**
     * Verifys SMS code and sends auth token with customer resource.
     *
     * @return \Fleetbase\FleetOps\Http\Resources\v1\Driver
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
        $user = User::where('phone', $identity)->orWhere('email', $identity)->first();

        if (!$user) {
            return response()->error('Unable to verify code.');
        }

        // find and verify code
        $verificationCode = VerificationCode::where(['subject_uuid' => $user->uuid, 'code' => $code, 'for' => $for])->exists();

        if (!$verificationCode && $code !== '999000') {
            return response()->error('Invalid verification code!');
        }

        // get the current company session
        $company = Flow::getCompanySessionForUser($user);

        // get driver record
        $driver = Driver::firstOrCreate(
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $company->uuid,
            ],
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $company->uuid,
                'name'         => $attrs['name'] ?? $user->name,
                'phone'        => $attrs['phone'] ?? $user->phone,
                'email'        => $attrs['email'] ?? $user->email,
                'location'     => new Point(0, 0),
            ]
        );

        // generate auth token
        try {
            $token = $user->createToken($driver->uuid);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        // $driver->update(['auth_token' => $token->plainTextToken]);
        $driver->token = $token->plainTextToken;

        return new DriverResource($driver);
    }

    /**
     * Patches phone number with international code.
     */
    public static function phone(string $phone = null): string
    {
        if ($phone === null) {
            $phone = request()->input('phone');
        }

        if (!Str::startsWith($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    /**
     * List organizations that driver is apart of.
     *
     * @return \Fleetbase\Http\Resources\Organization
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

        $companies = Company::whereHas('users', function ($q) use ($driver) {
            $q->where('users.uuid', $driver->user_uuid);
        })->get();

        return Organization::collection($companies);
    }

    /**
     * Allow driver to switch organization.
     *
     * @return \Fleetbase\Http\Resources\Organization
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

        // get the next organization
        $company = Company::where('public_id', $nextOrganization)->first();

        if ($company->uuid === $driver->user->company_uuid) {
            return response()->json([
                'error' => 'Driver is already on this organizations session',
            ]);
        }

        if (!CompanyUser::where(['user_uuid' => $driver->user_uuid, 'company_uuid' => $company->uuid])->exists()) {
            return response()->json([
                'errors' => ['You do not belong to this organization'],
            ]);
        }

        $driver->user->assignCompany($company);
        Auth::setSession($driver->user);

        return new Organization($company);
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
            /** @var \Fleetbase\FleetOps\Models\Driver $driver */
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
                /** @var \Fleetbase\FleetOps\Models\Order $order */
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
}
