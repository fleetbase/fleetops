<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException;
use Fleetbase\FleetOps\Http\Requests\CreateCustomerOrderRequest;
use Fleetbase\FleetOps\Http\Requests\CreateCustomerRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateContactRequest;
use Fleetbase\FleetOps\Http\Requests\VerifyCreateCustomerRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Customer as CustomerResource;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Support\CustomerAuth;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\LaravelMysqlSpatial\Types\Point as SpatialPoint;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Fleetbase\Models\UserDevice;
use Fleetbase\Models\VerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Customer-facing Fleet-Ops API.
 *
 * Mirrors the Storefront customer surface (signup with verification code,
 * email/password login, SMS login, customer-scoped orders & places) but
 * authenticates entirely with the Fleet-Ops API credential (`flb_live_…`) on
 * the public routes and a Sanctum `Customer-Token` (issued by signup/login)
 * on the authenticated routes. The company tenancy boundary is enforced by
 * the standard `fleetbase.api` middleware that resolves the API credential
 * to a `company_uuid` and sets it on the session.
 */
class CustomerController extends Controller
{
    use \Fleetbase\FleetOps\Http\Controllers\Concerns\ResolvesReviewAccountBypass;

    /* ============================================================
     | Public auth flows (API credential only, no Customer-Token)
     * ============================================================ */

    /**
     * Send an email or SMS verification code so a new customer can complete signup.
     *
     * @return JsonResponse
     */
    public function requestCreationCode(VerifyCreateCustomerRequest $request)
    {
        $mode     = $request->input('mode', 'email');
        $identity = $request->input('identity');
        $isEmail  = $this->isEmail($identity);

        if ($mode === 'email' && !$isEmail) {
            return response()->apiError('Invalid email provided for identity.');
        }

        if ($mode === 'sms') {
            $identity = static::phone($identity);
        }

        $sessionCompany = $this->sessionCompany();
        if (!$sessionCompany) {
            return response()->apiError('No company resolved from API credential.', 500);
        }

        // Optional profile fields the client can include up front so the
        // verification email greets the customer by name (and so a later
        // `create()` doesn't need to overwrite stub values).
        $providedName  = trim((string) $request->input('name', ''));
        $providedPhone = $request->filled('phone') ? static::phone($request->input('phone')) : null;

        // The verification code needs a persisted subject so the mail renderer
        // can resolve the polymorphic `subject` relation (the verification blade
        // template references `$user->name`). Look up — or create — the User
        // before sending. `create()` later backfills password + remaining fields
        // on this same row when the customer confirms the code.
        $subject = $this->findActiveUserByIdentity($identity, $isEmail ? 'email' : 'phone');

        if (!$subject) {
            // `password` and `type` are guarded on User; assign type after create
            // via setUserType (saves the row).
            $subject = $this->createUser([
                'company_uuid' => $sessionCompany,
                'name'         => $providedName !== '' ? $providedName : $identity,
                'email'        => $isEmail ? $identity : null,
                'phone'        => $isEmail ? $providedPhone : $identity,
            ]);
            $subject->setUserType('customer');
        } elseif ($providedName !== '' && (!$subject->name || $subject->name === $subject->email)) {
            // Existing stub user from a prior incomplete signup — refresh the
            // greeting name if the client supplied one.
            $subject->name = $providedName;
            if ($isEmail && $providedPhone && !$subject->phone) {
                $subject->phone = $providedPhone;
            }
            $subject->save();
        }

        $meta = ['identity' => $identity];

        try {
            if ($mode === 'email') {
                $this->generateEmailVerification($subject, 'fleetops_create_customer', [
                    'subject'         => config('app.name') . ' verification code',
                    'messageCallback' => fn ($verification) => 'Your ' . config('app.name') . ' verification code is ' . $verification->code,
                    'meta'            => $meta,
                ]);
            } else {
                $this->generateSmsVerification($subject, 'fleetops_create_customer', [
                    'messageCallback' => fn ($verification) => 'Your ' . config('app.name') . ' verification code is ' . $verification->code,
                    'meta'            => $meta,
                ]);
            }
        } catch (\Twilio\Exceptions\RestException $e) {
            return response()->apiError($e->getMessage());
        } catch (\Exception $e) {
            return response()->apiError(app()->hasDebugModeEnabled() ? $e->getMessage() : 'Error sending verification code.');
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Create a new customer (Contact + User) after verifying their code.
     */
    public function create(CreateCustomerRequest $request)
    {
        $code     = $request->input('code');
        $identity = $request->input('identity');
        $isEmail  = $this->isEmail($identity);
        if (!$isEmail) {
            $identity = static::phone($identity);
        }

        // Verify the code is one we sent for this identity.
        $verificationCode = $this->verificationCodeExists([
            'code'           => $code,
            'for'            => 'fleetops_create_customer',
            'meta->identity' => $identity,
        ]);
        if (!$verificationCode && !$this->verificationBypassMatches($identity, $code)) {
            return response()->apiError('Invalid verification code provided.');
        }

        $sessionCompany = $this->sessionCompany();
        if (!$sessionCompany) {
            return response()->apiError('No company resolved from API credential.', 500);
        }

        // Attach to existing User if one matches the identity, otherwise create one.
        $user = null;
        if ($isEmail) {
            $user = $this->findActiveUserByIdentity($identity, 'email');
        } elseif (Str::startsWith($identity, '+')) {
            $user = $this->findActiveUserByIdentity($identity, 'phone');
        }

        if (!$user) {
            // `password` and `type` are guarded on User; assign them after create
            // (setUserType saves the type, setPasswordAttribute hashes plaintext).
            $user = $this->createUser([
                'company_uuid' => $sessionCompany,
                'name'         => $request->input('name'),
                'email'        => $isEmail ? $identity : $request->input('email'),
                'phone'        => $isEmail ? static::phone($request->input('phone')) : $identity,
            ]);
            $user->password = $request->input('password');
            $user->save();
            $user->setUserType('customer');
        } else {
            // User row exists. If it's a stub created during request-creation-code
            // (no password set yet), backfill name + password + email/phone from
            // the signup form. If a password is already set this is an existing
            // account — only attach a new Contact, don't overwrite credentials.
            if (!$user->password) {
                $update = ['name' => $request->input('name')];
                if ($isEmail && !$user->phone && $request->filled('phone')) {
                    $update['phone'] = static::phone($request->input('phone'));
                }
                if (!$isEmail && !$user->email && $request->filled('email')) {
                    $update['email'] = $request->input('email');
                }
                $user->fill($update);
                $user->password = $request->input('password'); // mutator hashes
                $user->save();
                if (!$user->type) {
                    $user->setUserType('customer');
                }
            }
        }

        // `meta` is a client-owned free-form bag — we pass through whatever the
        // client sent without injecting controller-side keys. The API should
        // only stamp meta when the backend itself needs the data to operate
        // (cf. Storefront's `meta.storefront_id` for query scoping).
        $input = [
            'type'         => 'customer',
            'company_uuid' => $sessionCompany,
            'user_uuid'    => $user->uuid,
            'name'         => $request->input('name') ?: $user->name,
            'title'        => $request->input('title'),
            'email'        => $isEmail ? $identity : ($request->input('email') ?: $user->email),
            'phone'        => $isEmail ? static::phone($request->input('phone')) : $identity,
            'meta'         => (array) $request->input('meta', []),
        ];

        // Handle photo as either file id or base64 data.
        $photo = $request->input('photo');
        if ($photo) {
            if ($this->isPublicId($photo)) {
                $file = $this->findFileByPublicId($photo);
                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }
            if ($this->isBase64String($photo)) {
                $path = implode('/', ['uploads', $sessionCompany, 'customers']);
                $file = $this->createFileFromBase64($photo, $path);
                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }
        }

        // Reuse an existing customer-Contact for this user+company if one exists
        // (idempotent re-signup), otherwise create one.
        $contact = $this->findCustomerContact([
            'company_uuid' => $sessionCompany,
            'user_uuid'    => $user->uuid,
            'type'         => 'customer',
        ]);
        if ($contact) {
            $contact->fill(array_filter($input, fn ($v) => $v !== null && $v !== ''))->save();
        } else {
            try {
                $contact = $this->createContact($input);
            } catch (UserAlreadyExistsException $e) {
                $contact = $this->findCustomerContact([
                    'company_uuid' => $sessionCompany,
                    'user_uuid'    => $user->uuid,
                    'type'         => 'customer',
                ]);
                if (!$contact) {
                    return response()->apiError($e->getMessage());
                }
            } catch (\Exception $e) {
                return response()->apiError($e->getMessage());
            }
        }

        // Optionally link a default Place to the new customer. Accepts either:
        //   - a string public_id of an existing Place in this company
        //   - a Place-shaped object using canonical fields:
        //       name, street1, street2, city, province, postal_code, country,
        //       neighborhood, district, building, phone, meta
        // The created Place is owned by the customer Contact (polymorphic via
        // owner_uuid + owner_type). Idempotent: only acts when no place is
        // already linked.
        if (!$contact->place_uuid) {
            $place = $this->resolveCustomerPlace($request->input('place'), $contact, $sessionCompany);
            if ($place) {
                $contact->place_uuid = $place->uuid;
                $contact->save();
            }
        }

        $token          = $this->createCustomerToken($user, $contact);
        $contact->token = $token->plainTextToken;

        return $this->customerResource($contact);
    }

    /**
     * Resolve the customer's default Place reference. Accepts either:
     *  - a string public_id of an existing Place (must be in the same company)
     *  - an array of canonical Place fields (Place::$fillable subset)
     *
     * Returns null when no place data was provided. Mirrors the convention
     * used by other v1 controllers that accept a `place` reference.
     */
    protected function resolveCustomerPlace($input, Contact $contact, string $companyUuid): ?Place
    {
        if (empty($input)) {
            return null;
        }

        if (is_string($input)) {
            return $this->findPlaceByPublicId($input, $companyUuid);
        }

        if (!is_array($input)) {
            return null;
        }

        // Filter the supplied attributes to Place's fillable list — never accept
        // arbitrary client-specific keys at this surface.
        $allowed    = ['name', 'street1', 'street2', 'city', 'province', 'postal_code', 'neighborhood', 'district', 'building', 'security_access_code', 'country', 'phone', 'meta', 'type'];
        $attributes = array_intersect_key($input, array_flip($allowed));

        if (!array_filter($attributes, fn ($v) => $v !== null && $v !== '')) {
            return null;
        }

        return $this->createPlace(array_merge(
            [
                'company_uuid' => $companyUuid,
                'owner_uuid'   => $contact->uuid,
                'owner_type'   => get_class($contact),
                // `places.location` is a NOT NULL POINT column with no database default,
                // and $allowed above is address-only — a caller cannot supply coordinates
                // here. Without this the insert fails with SQLSTATE[HY000] 1364 ("Field
                // 'location' doesn't have a default value"), turning a documented signup
                // payload into a 500. Point(0, 0) is the same placeholder the geocoding
                // helpers fall back to when an address cannot be resolved — see
                // Place::getGoogleAddressArray and Place::findExistingSharedPlace.
                'location'     => new SpatialPoint(0, 0),
            ],
            $attributes,
        ));
    }

    /**
     * Authenticate an existing customer with email/phone + password.
     */
    public function login(Request $request)
    {
        $identity = $request->input('identity');
        $password = $request->input('password');
        if (!$identity || !$password) {
            return response()->apiError('Identity and password are required.', 400);
        }

        $user = $this->findUserForLogin($identity);

        if (!$user || !$user->password || !$this->passwordMatches($password, $user->password)) {
            return response()->apiError('Authentication failed using credentials provided.', 401);
        }

        $sessionCompany = $this->sessionCompany();
        if (!$sessionCompany) {
            return response()->apiError('No company resolved from API credential.', 500);
        }

        $contact = $this->firstOrCreateCustomerContact(
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $sessionCompany,
                'type'         => 'customer',
            ],
            [
                'name'  => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
            ]
        );

        $token          = $this->createCustomerToken($user, $contact);
        $contact->token = $token->plainTextToken;

        return $this->customerResource($contact);
    }

    /**
     * Send an SMS verification code so a customer can log in without password.
     */
    public function loginWithPhone(Request $request)
    {
        $phone = static::phone($request->input('phone') ?? $request->input('identity'));

        $user = $this->findActiveUserByIdentity($phone, 'phone');
        if (!$user) {
            return response()->apiError('No customer with this phone number found.');
        }

        try {
            $this->generateSmsVerification($user, 'fleetops_customer_login', [
                'messageCallback' => fn ($verification) => 'Your ' . config('app.name') . ' verification code is ' . $verification->code,
            ]);

            return response()->json(['status' => 'ok', 'method' => 'sms']);
        } catch (\Throwable $e) {
            if ($user->email) {
                try {
                    $this->generateEmailVerification($user, 'fleetops_customer_login', [
                        'subject'         => config('app.name') . ' verification code',
                        'messageCallback' => fn ($verification) => 'Your ' . config('app.name') . ' verification code is ' . $verification->code,
                    ]);

                    return response()->json(['status' => 'ok', 'method' => 'email']);
                } catch (\Throwable $inner) {
                    return response()->apiError('Unable to send verification code.');
                }
            }
        }

        return response()->apiError('Unable to send verification code.');
    }

    /**
     * Verify the SMS/email code from {@see loginWithPhone} and issue a token.
     */
    public function verifyCode(Request $request)
    {
        $identity = $this->isEmail($request->input('identity')) ? $request->input('identity') : static::phone($request->input('identity'));
        $code     = $request->input('code');
        $for      = $request->input('for', 'fleetops_customer_login');

        if ($for === 'fleetops_create_customer') {
            return $this->create(CreateCustomerRequest::createFrom($request));
        }

        $user = $this->findUserForVerification($identity);
        if (!$user) {
            return response()->apiError('Unable to verify code.');
        }

        $verificationCode = $this->verificationCodeExists([
            'subject_uuid' => $user->uuid,
            'code'         => $code,
            'for'          => $for,
        ]);
        if (!$verificationCode && !$this->verificationBypassMatches($identity, $code)) {
            return response()->apiError('Invalid verification code.');
        }

        $sessionCompany = $this->sessionCompany();
        $contact        = $this->firstOrCreateCustomerContact(
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $sessionCompany,
                'type'         => 'customer',
            ],
            [
                'name'  => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
            ]
        );

        $token          = $this->createCustomerToken($user, $contact);
        $contact->token = $token->plainTextToken;

        return $this->customerResource($contact);
    }

    /**
     * Send a password-reset code to the customer's email or phone.
     */
    public function forgotPassword(Request $request)
    {
        $identity = $request->input('identity');
        if (!$identity) {
            return response()->apiError('Identity is required.', 400);
        }

        $isEmail = $this->isEmail($identity);
        $user    = $this->findUserByIdentity($isEmail ? $identity : static::phone($identity), $isEmail ? 'email' : 'phone');

        if (!$user) {
            // Don't leak account existence — return success regardless.
            return response()->json(['status' => 'ok']);
        }

        $meta = ['identity' => $isEmail ? $identity : static::phone($identity)];
        try {
            if ($isEmail) {
                $this->generateEmailVerification($user, 'fleetops_customer_password_reset', [
                    'subject'         => config('app.name') . ' password reset',
                    'messageCallback' => fn ($v) => 'Your ' . config('app.name') . ' password reset code is ' . $v->code,
                    'meta'            => $meta,
                ]);
            } else {
                $this->generateSmsVerification($user, 'fleetops_customer_password_reset', [
                    'messageCallback' => fn ($v) => 'Your ' . config('app.name') . ' password reset code is ' . $v->code,
                    'meta'            => $meta,
                ]);
            }
        } catch (\Throwable $e) {
            return response()->apiError(app()->hasDebugModeEnabled() ? $e->getMessage() : 'Unable to send reset code.');
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verify the password-reset code and set a new password.
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

        $isEmail = $this->isEmail($identity);
        $needle  = $isEmail ? $identity : static::phone($identity);

        $verificationCode = $this->findVerificationCode([
            'code'           => $code,
            'for'            => 'fleetops_customer_password_reset',
            'meta->identity' => $needle,
        ]);
        // $needle, not $identity: the other verify paths normalise in place, so an
        // allowlisted phone must be compared in the same normalised form here too.
        if (!$verificationCode && !$this->verificationBypassMatches($needle, $code)) {
            return response()->apiError('Invalid reset code.');
        }

        $user = $this->findUserByIdentity($needle, $isEmail ? 'email' : 'phone');
        if (!$user) {
            return response()->apiError('Account not found.');
        }

        // setPasswordAttribute mutator hashes plaintext; don't pre-hash here.
        $user->password = $password;
        $user->save();
        // Invalidate all existing sessions for this user after a password reset.
        $this->deleteUserTokens($user);
        // Null on the testing-bypass path — there is no row to consume.
        if ($verificationCode) {
            $verificationCode->delete();
        }

        return response()->json(['status' => 'ok']);
    }

    /* ============================================================
     | Authenticated flows (require Customer-Token)
     * ============================================================ */

    /**
     * Return the authenticated customer's profile.
     */
    public function me()
    {
        $customer = $this->currentCustomer();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        return $this->customerResource($customer);
    }

    /**
     * Update the authenticated customer's profile (Contact + linked User fields).
     */
    public function updateMe(UpdateContactRequest $request)
    {
        $customer = $this->currentCustomer();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        $input = $request->only(['name', 'title', 'email', 'phone', 'meta']);
        if (isset($input['phone'])) {
            $input['phone'] = static::phone($input['phone']);
        }

        // Photo handling.
        $photo = $request->input('photo');
        if ($photo) {
            if ($this->isPublicId($photo)) {
                $file = $this->findFileByPublicId($photo);
                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }
            if ($this->isBase64String($photo)) {
                $path = implode('/', ['uploads', $this->sessionCompany(), 'customers']);
                $file = $this->createFileFromBase64($photo, $path);
                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }
            if ($photo === 'REMOVE') {
                $input['photo_uuid'] = null;
            }
        }

        try {
            $customer->update($input);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        // Mirror critical fields on the linked User row so login works after edits.
        if ($customer->user_uuid) {
            $userUpdate = array_filter([
                'name'  => $input['name'] ?? null,
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
            ], fn ($v) => $v !== null);
            if (!empty($userUpdate)) {
                $this->updateUserByUuid($customer->user_uuid, $userUpdate);
            }
        }

        return $this->customerResource($customer->fresh());
    }

    /**
     * Revoke the Customer-Token used on this request.
     */
    public function logout(Request $request)
    {
        $customer = $this->currentCustomer();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        $tokenString = $request->header(CustomerAuth::HEADER);
        $accessToken = $tokenString ? $this->findAccessToken($tokenString) : null;
        if ($accessToken) {
            $accessToken->delete();
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Revoke ALL tokens for the authenticated customer's user (sign out everywhere).
     */
    public function logoutAll()
    {
        $customer = $this->currentCustomer();
        if (!$customer || !$customer->user_uuid) {
            return response()->apiError('Not authenticated.', 401);
        }

        $user = $this->findUserByUuid($customer->user_uuid);
        if ($user) {
            $this->deleteUserTokens($user);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * List the authenticated customer's orders.
     */
    public function orders(Request $request)
    {
        $customer = $this->currentCustomer();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        $results = $this->queryOrders($request, function (&$query) use ($customer) {
            $query->where('customer_uuid', $customer->uuid)
                ->whereNull('deleted_at')
                ->withoutGlobalScopes();
        });

        return $this->orderResourceCollection($results);
    }

    /**
     * Fetch one order owned by the authenticated customer.
     */
    public function findOrder(string $id)
    {
        $customer = $this->currentCustomer();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        try {
            $order = $this->findOrderOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->apiError('Order not found.', 404);
        }

        if ($order->customer_uuid !== $customer->uuid) {
            return response()->apiError('Order not found.', 404);
        }

        return $this->orderResource($order);
    }

    /**
     * Create an Order on behalf of the authenticated customer.
     *
     * Accepts the canonical Fleet-Ops Order create shape (the same fields as
     * `POST /v1/orders` would accept from an operator): `type` / `order_config`,
     * `scheduled_at`, `notes`, `meta`, plus either a top-level `payload`
     * (object or public_id) or top-level `pickup` / `dropoff` / `waypoints` /
     * `entities` that the controller rolls into a Payload — using the
     * Payload model's canonical setters for parity with OrderController.
     *
     * `customer_uuid` + `customer_type` are forced from the Customer-Token;
     * any client-supplied `customer` field is ignored. `status` is forced to
     * `created` (customers cannot self-dispatch).
     */
    public function createOrder(CreateCustomerOrderRequest $request)
    {
        $customer = $this->currentCustomer();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        $sessionCompany = $this->sessionCompany();
        if (!$sessionCompany) {
            return response()->apiError('No company resolved from API credential.', 500);
        }

        // Resolve the order config for this order. Mirrors OrderController::create.
        $orderConfig = $this->resolveOrderConfig($request, $sessionCompany);
        if (!$orderConfig) {
            return response()->apiError('No order config available for this company.', 422);
        }

        // Build the Payload (matching the operator OrderController convention).
        // - `payload` may be an array of {pickup, dropoff, return, waypoints, entities}
        // - `payload` may be a string public_id referencing an existing Payload
        // - Or top-level pickup/dropoff/return/waypoints/entities are accepted
        $payloadUuid = null;
        if ($request->isArray('payload')) {
            $payloadInput = (array) $request->input('payload');
            $payloadUuid  = $this->buildPayloadFromInput($payloadInput, $sessionCompany)->uuid;
        } elseif ($request->isString('payload')) {
            $payloadUuid = $this->getUuid('payloads', [
                'public_id'    => $request->input('payload'),
                'company_uuid' => $sessionCompany,
            ]);
        } else {
            $payloadInput = $request->only(['pickup', 'dropoff', 'return', 'waypoints', 'entities']);
            if (array_filter($payloadInput, fn ($v) => $v !== null && $v !== '' && $v !== [])) {
                $payloadUuid = $this->buildPayloadFromInput($payloadInput, $sessionCompany)->uuid;
            }
        }

        $order = $this->createOrderRecord([
            'company_uuid'      => $sessionCompany,
            'customer_uuid'     => $customer->uuid,
            'customer_type'     => $this->getModelClassName('contact'),
            'payload_uuid'      => $payloadUuid,
            'order_config_uuid' => $orderConfig->uuid,
            'type'              => $orderConfig->key,
            'status'            => 'created',
            'scheduled_at'      => $request->input('scheduled_at'),
            'notes'             => $request->input('notes'),
            'internal_id'       => $request->input('internal_id'),
            'meta'              => (array) $request->input('meta', []),
        ]);

        // If the customer picked a ServiceQuote up front, consume it now to
        // lock the pricing onto the order's PurchaseRate (mirrors how
        // OrderController::create handles `service_quote`).
        $serviceQuote = $this->resolveServiceQuote($request);
        if ($serviceQuote instanceof ServiceQuote) {
            $order->purchaseServiceQuote($serviceQuote);
        }

        return $this->orderResource($order->fresh(['payload', 'payload.pickup', 'payload.dropoff', 'payload.entities']));
    }

    /**
     * Build a Payload from the canonical {pickup, dropoff, return, waypoints,
     * entities} shape. Identical to OrderController::create's payload-building
     * branch so customer-created orders are indistinguishable from operator-
     * created ones at the data layer.
     */
    protected function buildPayloadFromInput(array $payloadInput, string $companyUuid): Payload
    {
        $payload   = $this->newPayload();
        $entities  = data_get($payloadInput, 'entities', []);
        $waypoints = data_get($payloadInput, 'waypoints', []);
        $pickup    = data_get($payloadInput, 'pickup');
        $dropoff   = data_get($payloadInput, 'dropoff');
        $return    = data_get($payloadInput, 'return');

        $payload->company_uuid = $companyUuid;

        if ($pickup) {
            $payload->setPickup($pickup, [
                'callback' => function ($pickup, $payload) {
                    $payload->setCurrentWaypoint($pickup);
                },
            ]);
        }
        if ($dropoff) {
            $payload->setDropoff($dropoff);
        }
        if ($return) {
            $payload->setReturn($return);
        }

        $payload->save();

        $payload->setWaypoints($waypoints);
        $payload->setEntities($entities);

        $firstWaypoint = $payload->getPickupOrFirstWaypoint();
        if ($firstWaypoint instanceof Place) {
            $payload->setCurrentWaypoint($firstWaypoint);
        }

        return $payload;
    }

    /**
     * List the authenticated customer's saved places.
     */
    public function places(Request $request)
    {
        $customer = $this->currentCustomer();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        $results = $this->queryPlaces($request, function (&$query) use ($customer) {
            $query->where('owner_uuid', $customer->uuid);
        });

        return $this->placeResourceCollection($results);
    }

    /**
     * Register a push-notification device for the authenticated customer's user.
     */
    public function registerDevice(Request $request)
    {
        $customer = $this->currentCustomer();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        $device = $this->firstOrCreateDevice(
            [
                'token'    => $request->input('token'),
                'platform' => $request->input('platform', $request->input('os')),
            ],
            [
                'user_uuid' => $customer->user_uuid,
                'platform'  => $request->input('platform', $request->input('os')),
                'token'     => $request->input('token'),
                'status'    => 'active',
            ]
        );

        return response()->json([
            'status' => 'ok',
            'device' => $device->public_id,
        ]);
    }

    /* ============================================================
     | Helpers
     * ============================================================ */

    protected function isEmail(?string $identity): bool
    {
        return Utils::isEmail($identity);
    }

    protected function isPublicId(?string $value): bool
    {
        return Utils::isPublicId($value);
    }

    protected function isBase64String(?string $value): bool
    {
        return Utils::isBase64String($value);
    }

    protected function sessionCompany(): ?string
    {
        return session('company');
    }

    protected function currentCustomer(): ?Contact
    {
        return CustomerAuth::current();
    }

    protected function findActiveUserByIdentity(string $identity, string $column): ?User
    {
        return User::where($column, $identity)->whereNull('deleted_at')->withoutGlobalScopes()->first();
    }

    protected function findUserByIdentity(string $identity, string $column): ?User
    {
        return User::where($column, $identity)->first();
    }

    protected function findUserForLogin(string $identity): ?User
    {
        return User::where('email', $identity)
            ->orWhere('phone', static::phone($identity))
            ->first();
    }

    protected function findUserForVerification(string $identity): ?User
    {
        return User::where('phone', $identity)->orWhere('email', $identity)->first();
    }

    protected function findUserByUuid(string $uuid): ?User
    {
        return User::where('uuid', $uuid)->first();
    }

    protected function createUser(array $attributes): User
    {
        return User::create($attributes);
    }

    protected function passwordMatches(string $password, string $hash): bool
    {
        return Hash::check($password, $hash);
    }

    protected function generateEmailVerification(User $user, string $for, array $options): mixed
    {
        return VerificationCode::generateEmailVerificationFor($user, $for, $options);
    }

    protected function generateSmsVerification(User $user, string $for, array $options): mixed
    {
        return VerificationCode::generateSmsVerificationFor($user, $for, $options);
    }

    /**
     * Whether the supplied code matches the configured testing bypass code.
     *
     * Three conditions, all required, mirroring the console equivalent in
     * Fleetbase\Http\Controllers\Internal\v1\AuthController::authenticateWithVerificationCode:
     * a bypass code must actually be configured, the app must not be running in
     * production, and the comparison is constant-time.
     *
     * Fails safe by default: config/app.php resolves `env` to `production` when
     * neither APP_ENV nor ENVIRONMENT is set, so an unconfigured install cannot
     * be bypassed even accidentally.
     *
     * `!== null && !== ''` rather than `!empty()` — `!empty('0')` is false, so a
     * configured bypass code of "0" would otherwise be silently ignored.
     *
     * Kept out of verificationCodeExists()/findVerificationCode() on purpose:
     * those two are test seams that the controller contract tests override, so a
     * policy living inside them would be stubbed away exactly where it matters.
     */
    protected function verificationBypassMatches(?string $identity, ?string $code): bool
    {
        return static::reviewAccountBypassMatches(
            'fleetops.customers.verification_bypass_code',
            'fleetops.customers.review_accounts',
            $identity,
            $code,
            'fleetops-customer'
        );
    }

    protected function verificationCodeExists(array $attributes): bool
    {
        return VerificationCode::where($attributes)->exists();
    }

    protected function findVerificationCode(array $attributes): mixed
    {
        return VerificationCode::where($attributes)->first();
    }

    protected function findFileByPublicId(string $publicId): mixed
    {
        return File::where('public_id', $publicId)->first();
    }

    protected function createFileFromBase64(string $contents, string $path): mixed
    {
        return File::createFromBase64($contents, null, $path);
    }

    protected function findCustomerContact(array $attributes): ?Contact
    {
        return Contact::where($attributes)->first();
    }

    protected function createContact(array $attributes): Contact
    {
        return Contact::create($attributes);
    }

    protected function firstOrCreateCustomerContact(array $attributes, array $values): Contact
    {
        return Contact::firstOrCreate($attributes, $values);
    }

    protected function createCustomerToken(User $user, Contact $contact): mixed
    {
        return $user->createToken($contact->uuid);
    }

    protected function findPlaceByPublicId(string $publicId, string $companyUuid): ?Place
    {
        return Place::where(['public_id' => $publicId, 'company_uuid' => $companyUuid])->first();
    }

    protected function createPlace(array $attributes): Place
    {
        return Place::create($attributes);
    }

    protected function updateUserByUuid(string $uuid, array $attributes): mixed
    {
        return User::where('uuid', $uuid)->update($attributes);
    }

    protected function findAccessToken(string $token): mixed
    {
        return \Laravel\Sanctum\PersonalAccessToken::findToken($token);
    }

    protected function deleteUserTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    protected function queryOrders(Request $request, callable $callback): mixed
    {
        return Order::queryWithRequest($request, $callback);
    }

    protected function findOrderOrFail(string $id): Order
    {
        return Order::findRecordOrFail($id);
    }

    protected function resolveOrderConfig(CreateCustomerOrderRequest $request, string $companyUuid): ?OrderConfig
    {
        return OrderConfig::resolveFromIdentifier($request->only(['type', 'order_config']))
            ?: OrderConfig::where('company_uuid', $companyUuid)->first();
    }

    protected function getUuid(array|string $table, array $where, array $options = []): mixed
    {
        return Utils::getUuid($table, $where, $options);
    }

    protected function getModelClassName(?string $table): ?string
    {
        return Utils::getModelClassName($table);
    }

    protected function createOrderRecord(array $attributes): Order
    {
        return Order::create($attributes);
    }

    protected function resolveServiceQuote(CreateCustomerOrderRequest $request): mixed
    {
        return ServiceQuote::resolveFromRequest($request);
    }

    protected function newPayload(): Payload
    {
        return new Payload();
    }

    protected function queryPlaces(Request $request, callable $callback): mixed
    {
        return Place::queryWithRequest($request, $callback);
    }

    protected function firstOrCreateDevice(array $attributes, array $values): mixed
    {
        return UserDevice::firstOrCreate($attributes, $values);
    }

    protected function customerResource(Contact $contact): mixed
    {
        return new CustomerResource($contact);
    }

    protected function orderResource(Order $order): mixed
    {
        return new OrderResource($order);
    }

    protected function orderResourceCollection(mixed $results): mixed
    {
        return OrderResource::collection($results);
    }

    protected function placeResourceCollection(mixed $results): mixed
    {
        return PlaceResource::collection($results);
    }

    /**
     * Normalize a phone number to international format (with leading `+`).
     */
    public static function phone(?string $phone = null): string
    {
        if ($phone === null) {
            $phone = request()->input('phone', '');
        }
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '';
        }
        if (!Str::startsWith($phone, '+')) {
            $phone = '+' . ltrim($phone, '+');
        }

        return $phone;
    }
}
