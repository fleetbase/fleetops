<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\FleetOps\Exceptions\ProfileIdentityConflictException;
use Fleetbase\FleetOps\Exports\DriverExport;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController as ApiDriverController;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Requests\Internal\CreateDriverRequest;
use Fleetbase\FleetOps\Http\Requests\Internal\UpdateDriverRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as IndexOrderResource;
use Fleetbase\FleetOps\Imports\DriverImport;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\ProfileAccountManager;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Support\Auth;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class DriverController extends FleetOpsController
{
    use Traits\DriverSchedulingTrait;
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

        $this->normalizeDriverVehicleInput($request, $input);

        // create validation request
        $createDriverRequest = CreateDriverRequest::createFrom($request);
        $rules               = $createDriverRequest->rules();

        // manually validate request
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            return $createDriverRequest->responseWithErrors($validator);
        }

        try {
            $record = $this->model->createRecordFromRequest(
                $request,
                function (&$request, &$input) {
                    $input = collect($input);

                    // Get current session company
                    $company = Auth::getCompany();
                    if (!$company) {
                        throw new \Exception('Unable to create driver.');
                    }

                    // The login account is managed by the driver profile: a team member
                    // with the email/phone is linked, otherwise a driver account is created.
                    $avatarUuid = Str::isUuid($input->get('photo_uuid')) ? $input->get('photo_uuid') : $input->get('avatar_uuid');
                    $user       = ProfileAccountManager::resolveForProfile(
                        $company->uuid,
                        'driver',
                        $input->get('name'),
                        $input->get('email'),
                        $input->get('phone'),
                        User::applyUserInfoFromRequest($request, array_filter([
                            'password'    => $input->get('password'),
                            'status'      => 'active',
                            'avatar_uuid' => $avatarUuid,
                        ]))
                    );

                    // Prepare input
                    $input = $input
                            ->except(['name', 'password', 'email', 'phone', 'meta', 'avatar_uuid', 'photo_uuid', 'status', 'user_uuid', 'user'])
                            ->filter()
                            ->toArray();

                    $input['company_uuid'] = $company->uuid;
                    $input['user_uuid']    = $user->uuid;
                    $input['slug']         = $user->slug;

                    // If no location provided set
                    if (empty($input['location'])) {
                        $input['location'] = new Point(0, 0);
                    }
                },
                function ($request, &$driver) {
                    $driver->load(['user']);
                    $customFieldValues = $request->array('driver.custom_field_values');
                    if ($customFieldValues) {
                        $driver->syncCustomFieldValues($customFieldValues);
                    }
                }
            );

            return ['driver' => new $this->resource($record)];
        } catch (ProfileIdentityConflictException $e) {
            return response()->error($e->getMessage(), 422, ['field' => $e->getField()]);
        } catch (QueryException $e) {
            return response()->error(env('DEBUG') ? $e->getMessage() : 'Error occurred while trying to create a ' . $this->resourceSingularlName);
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
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

        $this->normalizeDriverVehicleInput($request, $input);

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
                    $userInput = $input->only(['name', 'password', 'email', 'phone', 'avatar_uuid'])->reject(fn ($value, $key) => $value === null && !in_array($key, ['email', 'phone'], true))->toArray();
                    // handle `photo_uuid`
                    if (isset($input['photo_uuid']) && Str::isUuid($input['photo_uuid'])) {
                        $userInput['avatar_uuid'] = $input['photo_uuid'];
                    }
                    $input     = $input->except(['name', 'password', 'email', 'phone', 'meta', 'avatar_uuid', 'photo_uuid', 'user_uuid', 'user'])->toArray();

                    // Update the driver's login account through its proxy fields
                    ProfileAccountManager::syncProxyFields($driver->getUser(), $userInput);

                    // Flush cache
                    $driver->flushAttributesCache();
                },
                function ($request, &$driver) {
                    $driver->load(['user']);
                    if ($driver->user) {
                        $driver->user->setHidden(['driver']);
                    }

                    $driver->setHidden(['user']);
                    $customFieldValues = $request->array('driver.custom_field_values');
                    if ($customFieldValues) {
                        $driver->syncCustomFieldValues($customFieldValues);
                    }
                }
            );

            return ['driver' => new $this->resource($record)];
        } catch (ProfileIdentityConflictException $e) {
            return response()->error($e->getMessage(), 422, ['field' => $e->getField()]);
        } catch (QueryException $e) {
            return response()->error(env('DEBUG') ? $e->getMessage() : 'Error occurred while trying to update a ' . $this->resourceSingularlName);
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }
    }

    /**
     * Generate a new password for the driver's login and send it to them.
     *
     * @return JsonResponse
     */
    public function sendCredentials(string $id)
    {
        [$driver, $user, $error] = $this->resolveDriverLogin($id);
        if ($error) {
            return $error;
        }

        try {
            $sentVia = ProfileAccountManager::sendCredentials($driver, $user);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        return response()->json(['status' => 'ok', 'sent_via' => $sentVia, 'driver' => $this->driverLoginPayload($driver)]);
    }

    /**
     * Set a new password for the driver's login, optionally sending it to them.
     *
     * @return JsonResponse
     */
    public function resetCredentials(Request $request, string $id)
    {
        $password = $request->input('password');
        if (!is_string($password) || strlen($password) < 8) {
            return response()->error('Password must be at least 8 characters.');
        }

        if ($password !== $request->input('password_confirmation')) {
            return response()->error('Passwords do not match.');
        }

        [$driver, $user, $error] = $this->resolveDriverLogin($id);
        if ($error) {
            return $error;
        }

        $user->changePassword($password);

        try {
            if ($request->boolean('send_credentials')) {
                ProfileAccountManager::deliverCredentials($driver, $user, $password);
            }
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        return response()->json(['status' => 'ok', 'driver' => $this->driverLoginPayload($driver)]);
    }

    /**
     * Stop the driver from signing in to the driver app.
     *
     * @return JsonResponse
     */
    public function deactivateLogin(string $id)
    {
        [$driver, $user, $error] = $this->resolveDriverLogin($id);
        if ($error) {
            return $error;
        }

        $user->deactivate();

        // Sign the driver out of the driver app
        $user->tokens()->delete();

        return response()->json(['status' => 'ok', 'driver' => $this->driverLoginPayload($driver)]);
    }

    /**
     * Allow the driver to sign in to the driver app again.
     *
     * @return JsonResponse
     */
    public function reactivateLogin(string $id)
    {
        [$driver, $user, $error] = $this->resolveDriverLogin($id);
        if ($error) {
            return $error;
        }

        $user->activate();

        return response()->json(['status' => 'ok', 'driver' => $this->driverLoginPayload($driver)]);
    }

    /**
     * Find the driver and its managed login account. A driver linked to a team
     * member's account has its login managed in IAM.
     *
     * @return array{0: ?Driver, 1: ?User, 2: ?JsonResponse}
     */
    protected function resolveDriverLogin(string $id): array
    {
        $driver = Driver::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->first();

        if (!$driver) {
            return [null, null, response()->error('Driver not found.', 404)];
        }

        $user = $driver->user_uuid ? User::where('uuid', $driver->user_uuid)->first() : null;
        if (!$user) {
            return [$driver, null, response()->error('This driver has no login account.')];
        }

        if (ProfileAccountManager::isStaffAccount($user)) {
            return [$driver, $user, response()->error('This driver signs in with a team member account. Manage this login in IAM.', 422)];
        }

        return [$driver, $user, null];
    }

    protected function driverLoginPayload(Driver $driver): array
    {
        $user = User::where('uuid', $driver->user_uuid)->first();

        return [
            'id'              => $driver->public_id,
            'uuid'            => $driver->uuid,
            'user_uuid'       => $driver->user_uuid,
            'is_staff_linked' => ProfileAccountManager::isStaffAccount($user),
            'login_status'    => $user?->status,
            'user'            => $user ? [
                'id'             => $user->public_id,
                'uuid'           => $user->uuid,
                'name'           => $user->name,
                'email'          => $user->email,
                'phone'          => $user->phone,
                'status'         => $user->status,
                'session_status' => $user->session_status,
            ] : null,
        ];
    }

    /**
     * Get all status options for an driver.
     *
     * @return \Illuminate\Http\Response
     */
    public function statuses()
    {
        $statuses = $this->statusOptionsForCompany(session('company'));

        return response()->json($statuses);
    }

    /**
     * Get all avatar options for an vehicle.
     *
     * @return \Illuminate\Http\Response
     */
    public function avatars()
    {
        $options = $this->driverAvatarOptions();

        return response()->json($options);
    }

    /**
     * Export the drivers to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('drivers-' . date('Y-m-d-H:i')) . '.' . $format);

        return static::downloadExport(new DriverExport($selections), $fileName);
    }

    /**
     * Assigns a driver to a specified order.
     *
     * @param Fleetbase\FleetOps\Http\Requests\Internal\AssignOrderRequest $request
     *
     * @return \Illuminate\Http\Response $response
     */
    public function assignOrder(Request $request, ?string $id = null)
    {
        $request->validate([
            'driver' => ($id ? 'nullable' : 'required') . '|string',
            'order'  => 'required|string',
        ]);

        $driverIdentifier = $id ?? $request->input('driver');
        $orderIdentifier  = $request->input('order');

        $driver = $this->findDriver($driverIdentifier);
        $order  = $this->findOrder($orderIdentifier);

        if ($order->hasDriverAssigned) {
            return response()->error('A driver is already assigned to this order.');
        }

        if ($order->isDriver($driver)) {
            return response()->error('The driver is already assigned to this order.');
        }

        $order->assignDriver($driver);
        $driver->update(['current_job_uuid' => $order->uuid]);

        return response()->json([
            'status'  => 'ok',
            'message' => 'Driver assigned',
            'driver'  => $driver->fresh(['vehicle', 'currentOrder']),
            'order'   => $order->fresh(['driverAssigned']),
        ]);
    }

    public function assignedOrders(string $id): JsonResponse
    {
        $driver = $this->findDriver($id);
        $orders = $this->assignedOrdersForDriver($driver);

        return $this->jsonResponse([
            'status'  => 'ok',
            'driver'  => $driver->fresh(['vehicle', 'currentOrder']),
            'orders'  => $this->indexOrderCollection($orders),
            'current' => $driver->current_job_uuid,
            'count'   => $orders->count(),
        ]);
    }

    public function unassignOrders(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'orders'   => 'required|array|min:1',
            'orders.*' => 'required|string',
        ]);

        $driver = $this->findDriver($id);
        $ids    = collect($request->input('orders'))->filter()->unique()->values();
        $orders = $this->selectedAssignedOrdersForDriver($driver, $ids);

        if ($orders->isEmpty()) {
            return $this->errorResponse('No assigned orders were selected for this driver.');
        }

        $this->runTransaction(function () use ($driver, $orders): void {
            $this->clearDriverAssignmentsForOrders($orders);

            if ($driver->current_job_uuid && $orders->contains('uuid', $driver->current_job_uuid)) {
                $driver->update(['current_job_uuid' => null]);
            }
        });

        return $this->jsonResponse([
            'status'  => 'ok',
            'message' => 'Driver unassigned from selected orders.',
            'driver'  => $driver->fresh(['vehicle', 'currentOrder']),
            'orders'  => $this->indexOrderCollection($this->freshOrders($orders, ['driverAssigned', 'vehicleAssigned'])),
            'count'   => $orders->count(),
        ]);
    }

    public function unassignOrder(string $id): JsonResponse
    {
        $driver = $this->findDriver($id);
        $order  = $this->currentAssignedOrderForDriver($driver) ?? $driver->getCurrentOrder();

        if ($order) {
            $order->update(['driver_assigned_uuid' => null]);
        }

        $driver->unassignCurrentJob();

        return $this->jsonResponse([
            'status'  => 'ok',
            'message' => 'Driver unassigned from order.',
            'driver'  => $driver->fresh(['vehicle', 'currentOrder']),
            'order'   => $order?->fresh(['driverAssigned']),
        ]);
    }

    public function assignVehicle(Request $request, string $id): JsonResponse
    {
        $request->validate(['vehicle' => 'required|string']);

        $driver  = $this->findDriver($id);
        $vehicle = $this->findVehicle($request->input('vehicle'));

        $driver->assignVehicle($vehicle);

        return response()->json([
            'status'  => 'ok',
            'message' => 'Vehicle assigned to driver.',
            'driver'  => $driver->fresh(['vehicle', 'currentOrder']),
            'vehicle' => $vehicle->fresh(['driver']),
        ]);
    }

    public function unassignVehicle(string $id): JsonResponse
    {
        $driver  = $this->findDriver($id);
        $vehicle = $driver->vehicle;

        $driver->update(['vehicle_uuid' => null]);

        return response()->json([
            'status'  => 'ok',
            'message' => 'Vehicle unassigned from driver.',
            'driver'  => $driver->fresh(['vehicle', 'currentOrder']),
            'vehicle' => $vehicle?->fresh(['driver']),
        ]);
    }

    protected function findDriver(?string $id): Driver
    {
        return Driver::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->firstOrFail();
    }

    protected function findOrder(?string $id): Order
    {
        return Order::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->firstOrFail();
    }

    protected function findVehicle(?string $id): Vehicle
    {
        return Vehicle::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->firstOrFail();
    }

    protected function assignedOrdersForDriver(Driver $driver)
    {
        return Order::where('driver_assigned_uuid', $driver->uuid)
            ->where('company_uuid', session('company'))
            ->with(['payload', 'trackingNumber', 'orderConfig', 'driverAssigned', 'vehicleAssigned'])
            ->orderByRaw('uuid = ? desc', [$driver->current_job_uuid])
            ->latest()
            ->get();
    }

    protected function selectedAssignedOrdersForDriver(Driver $driver, $ids)
    {
        return Order::where('driver_assigned_uuid', $driver->uuid)
            ->where('company_uuid', session('company'))
            ->where(function ($query) use ($ids) {
                $query->whereIn('uuid', $ids)->orWhereIn('public_id', $ids);
            })
            ->get();
    }

    protected function currentAssignedOrderForDriver(Driver $driver): ?Order
    {
        return Order::where('driver_assigned_uuid', $driver->uuid)
            ->where('company_uuid', session('company'))
            ->first();
    }

    protected function clearDriverAssignmentsForOrders($orders): void
    {
        Order::whereIn('uuid', $orders->pluck('uuid'))->update([
            'driver_assigned_uuid' => null,
            'updated_at'           => now(),
        ]);
    }

    protected function freshOrders($orders, array $with)
    {
        return $orders->fresh($with);
    }

    protected function indexOrderCollection($orders): array
    {
        return IndexOrderResource::collection($orders)->resolve();
    }

    protected function runTransaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    protected function jsonResponse(array $payload): JsonResponse
    {
        return response()->json($payload);
    }

    protected function errorResponse(string $message): JsonResponse
    {
        return response()->error($message);
    }

    /**
     * Update drivers geolocation data.
     *
     * @return \Illuminate\Http\Response
     */
    public function track(string $id, Request $request)
    {
        return app(ApiDriverController::class)->track($id, $request);
    }

    /**
     * Query for Storefront Customer orders.
     *
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function registerDevice(Request $request)
    {
        // The public controller resolves the driver from the request when no id is given.
        return app(ApiDriverController::class)->registerDevice(null, $request);
    }

    /**
     * Authenticates customer using login credentials and returns with auth token.
     *
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function login(Request $request)
    {
        return app(ApiDriverController::class)->login($request);
    }

    /**
     * Attempts authentication with phone number via SMS verification.
     *
     * @return \Illuminate\Http\Response
     */
    public function loginWithPhone()
    {
        $phone = static::phone();

        // Check if user exists
        $user = static::findLoginUserByPhone($phone);

        if (!$user) {
            return response()->error('No driver with this phone # found.');
        }

        if (ApiDriverController::isLoginDeactivated($user)) {
            return response()->error(ApiDriverController::DEACTIVATED_LOGIN_MESSAGE, 403);
        }

        // Generate verification token
        static::generateDriverLoginVerification($user);

        return response()->json(['status' => 'OK']);
    }

    /**
     * Verifys SMS code and sends auth token with driver resource.
     *
     * @return \Fleetbase\Http\Resources\FleetOps\Driver
     */
    public function verifyCode(Request $request)
    {
        $identity = Utils::isEmail($request->identity) ? $request->identity : static::phone($request->identity);
        $code     = $request->input('code');
        $for      = $request->input('for', 'driver_login');
        $attrs    = $request->input(['name', 'phone', 'email']);

        if ($for === 'create_driver') {
            return app(ApiDriverController::class)->create($request);
        }

        // Check if user exists
        $user = static::findVerificationUser($identity);
        if (!$user) {
            return response()->error('Unable to verify code.');
        }

        if (ApiDriverController::isLoginDeactivated($user)) {
            return response()->error(ApiDriverController::DEACTIVATED_LOGIN_MESSAGE, 403);
        }

        // Find and verify code
        $verificationCode = static::verificationCodeExists($user, $code, $for);
        if (!$verificationCode && !ApiDriverController::verificationBypassMatches($identity, $code)) {
            return response()->error('Invalid verification code!');
        }

        // Get driver record
        $driver = static::findLoginDriverForUser($user);
        if (!$driver) {
            return response()->error('No driver/agent record found for login.');
        }

        // Generate auth token
        try {
            $token = static::createDriverToken($user, $driver);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        $driver->token = $token->plainTextToken;

        return new $this->resource($driver);
    }

    /**
     * Patches phone number with international code.
     */
    public static function phone(?string $phone = null): string
    {
        if ($phone === null) {
            $phone = request()->input('phone');
        }

        if (!Str::startsWith($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    protected function normalizeDriverVehicleInput(Request $request, ?array &$input): void
    {
        // Frontend forms may submit the full vehicle object; validation expects one identifier.
        if (!isset($input['vehicle']) || !is_array($input['vehicle'])) {
            return;
        }

        $input['vehicle'] = data_get($input['vehicle'], 'id')
            ?? data_get($input['vehicle'], 'public_id')
            ?? data_get($input['vehicle'], 'uuid')
            ?? null;

        $request->merge(['driver' => $input]);
    }

    protected static function findLoginUserByPhone(string $phone): ?User
    {
        return User::where('phone', $phone)->whereNull('deleted_at')->withoutGlobalScopes()->first();
    }

    protected static function generateDriverLoginVerification(User $user): void
    {
        VerificationCode::generateSmsVerificationFor($user, 'driver_login', [
            'messageCallback' => function ($verification) {
                return 'Your ' . config('app.name') . ' verification code is ' . $verification->code;
            },
        ]);
    }

    protected static function findVerificationUser(string $identity): ?User
    {
        return User::where('phone', $identity)->orWhere('email', $identity)->first();
    }

    protected static function verificationCodeExists(User $user, ?string $code, string $for): bool
    {
        return VerificationCode::where(['subject_uuid' => $user->uuid, 'code' => $code, 'for' => $for])->exists();
    }

    protected static function findLoginDriverForUser(User $user): ?Driver
    {
        return Driver::where('user_uuid', $user->uuid)->whereNull('deleted_at')->withoutGlobalScopes()->first();
    }

    protected static function createDriverToken(User $user, Driver $driver)
    {
        return $user->createToken($driver->uuid);
    }

    /**
     * Process import files (excel,csv) into Fleetbase order data.
     *
     * @return \Illuminate\Http\Response
     */
    public function import(ImportRequest $request)
    {
        $disk           = $request->input('disk', config('filesystems.default'));
        $files          = $request->resolveFilesFromIds();
        $importedCount  = 0;

        foreach ($files as $file) {
            try {
                $import = $this->createImport();
                $this->importFile($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to proccess.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }

    protected function statusOptionsForCompany(?string $companyUuid)
    {
        return DB::table('drivers')
            ->select('status')
            ->where('company_uuid', $companyUuid)
            ->distinct()
            ->get()
            ->pluck('status')
            ->filter()
            ->values();
    }

    protected function driverAvatarOptions(): array
    {
        return Driver::getAvatarOptions()->all();
    }

    protected static function downloadExport(DriverExport $export, string $fileName)
    {
        return Excel::download($export, $fileName);
    }

    protected function createImport(): DriverImport
    {
        return new DriverImport();
    }

    protected function importFile(DriverImport $import, string $path, string $disk): void
    {
        Excel::import($import, $path, $disk);
    }
}
