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
        $isEmail  = Utils::isEmail($identity);

        if ($mode === 'email' && !$isEmail) {
            return response()->apiError('Invalid email provided for identity.');
        }

        if ($mode === 'sms') {
            $identity = static::phone($identity);
        }

        $sessionCompany = session('company');
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
        $subject = $isEmail
            ? User::where('email', $identity)->whereNull('deleted_at')->withoutGlobalScopes()->first()
            : User::where('phone', $identity)->whereNull('deleted_at')->withoutGlobalScopes()->first();

        if (!$subject) {
            // `password` and `type` are guarded on User; assign type after create
            // via setUserType (saves the row).
            $subject = User::create([
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
                VerificationCode::generateEmailVerificationFor($subject, 'fleetops_create_customer', [
                    'subject'         => config('app.name') . ' verification code',
                    'messageCallback' => fn ($verification) => 'Your ' . config('app.name') . ' verification code is ' . $verification->code,
                    'meta'            => $meta,
                ]);
            } else {
                VerificationCode::generateSmsVerificationFor($subject, 'fleetops_create_customer', [
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
        $isEmail  = Utils::isEmail($identity);
        if (!$isEmail) {
            $identity = static::phone($identity);
        }

        // Verify the code is one we sent for this identity.
        $verificationCode = VerificationCode::where([
            'code'           => $code,
            'for'            => 'fleetops_create_customer',
            'meta->identity' => $identity,
        ])->exists();
        if (!$verificationCode) {
            return response()->apiError('Invalid verification code provided.');
        }

        $sessionCompany = session('company');
        if (!$sessionCompany) {
            return response()->apiError('No company resolved from API credential.', 500);
        }

        // Attach to existing User if one matches the identity, otherwise create one.
        $user = null;
        if ($isEmail) {
            $user = User::where('email', $identity)->whereNull('deleted_at')->withoutGlobalScopes()->first();
        } elseif (Str::startsWith($identity, '+')) {
            $user = User::where('phone', $identity)->whereNull('deleted_at')->withoutGlobalScopes()->first();
        }

        if (!$user) {
            // `password` and `type` are guarded on User; assign them after create
            // (setUserType saves the type, setPasswordAttribute hashes plaintext).
            $user = User::create([
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
            if (Utils::isPublicId($photo)) {
                $file = File::where('public_id', $photo)->first();
                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }
            if (Utils::isBase64String($photo)) {
                $path = implode('/', ['uploads', $sessionCompany, 'customers']);
                $file = File::createFromBase64($photo, null, $path);
                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }
        }

        // Reuse an existing customer-Contact for this user+company if one exists
        // (idempotent re-signup), otherwise create one.
        $contact = Contact::where([
            'company_uuid' => $sessionCompany,
            'user_uuid'    => $user->uuid,
            'type'         => 'customer',
        ])->first();
        if ($contact) {
            $contact->fill(array_filter($input, fn ($v) => $v !== null && $v !== ''))->save();
        } else {
            try {
                $contact = Contact::create($input);
            } catch (UserAlreadyExistsException $e) {
                $contact = Contact::where([
                    'company_uuid' => $sessionCompany,
                    'user_uuid'    => $user->uuid,
                    'type'         => 'customer',
                ])->first();
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

        $token          = $user->createToken($contact->uuid);
        $contact->token = $token->plainTextToken;

        return new CustomerResource($contact);
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
            return Place::where(['public_id' => $input, 'company_uuid' => $companyUuid])->first();
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

        return Place::create(array_merge(
            [
                'company_uuid' => $companyUuid,
                'owner_uuid'   => $contact->uuid,
                'owner_type'   => get_class($contact),
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

        $user = User::where('email', $identity)
            ->orWhere('phone', static::phone($identity))
            ->first();

        if (!$user || !$user->password || !Hash::check($password, $user->password)) {
            return response()->apiError('Authentication failed using credentials provided.', 401);
        }

        $sessionCompany = session('company');
        if (!$sessionCompany) {
            return response()->apiError('No company resolved from API credential.', 500);
        }

        $contact = Contact::firstOrCreate(
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

        $token          = $user->createToken($contact->uuid);
        $contact->token = $token->plainTextToken;

        return new CustomerResource($contact);
    }

    /**
     * Send an SMS verification code so a customer can log in without password.
     */
    public function loginWithPhone(Request $request)
    {
        $phone = static::phone($request->input('phone') ?? $request->input('identity'));

        $user = User::where('phone', $phone)->whereNull('deleted_at')->withoutGlobalScopes()->first();
        if (!$user) {
            return response()->apiError('No customer with this phone number found.');
        }

        try {
            VerificationCode::generateSmsVerificationFor($user, 'fleetops_customer_login', [
                'messageCallback' => fn ($verification) => 'Your ' . config('app.name') . ' verification code is ' . $verification->code,
            ]);

            return response()->json(['status' => 'ok', 'method' => 'sms']);
        } catch (\Throwable $e) {
            if ($user->email) {
                try {
                    VerificationCode::generateEmailVerificationFor($user, 'fleetops_customer_login', [
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
        $identity = Utils::isEmail($request->input('identity')) ? $request->input('identity') : static::phone($request->input('identity'));
        $code     = $request->input('code');
        $for      = $request->input('for', 'fleetops_customer_login');

        if ($for === 'fleetops_create_customer') {
            return $this->create($request);
        }

        $user = User::where('phone', $identity)->orWhere('email', $identity)->first();
        if (!$user) {
            return response()->apiError('Unable to verify code.');
        }

        $verificationCode = VerificationCode::where([
            'subject_uuid' => $user->uuid,
            'code'         => $code,
            'for'          => $for,
        ])->exists();
        if (!$verificationCode) {
            return response()->apiError('Invalid verification code.');
        }

        $sessionCompany = session('company');
        $contact        = Contact::firstOrCreate(
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

        $token          = $user->createToken($contact->uuid);
        $contact->token = $token->plainTextToken;

        return new CustomerResource($contact);
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

        $isEmail = Utils::isEmail($identity);
        $user    = $isEmail
            ? User::where('email', $identity)->first()
            : User::where('phone', static::phone($identity))->first();

        if (!$user) {
            // Don't leak account existence — return success regardless.
            return response()->json(['status' => 'ok']);
        }

        $meta = ['identity' => $isEmail ? $identity : static::phone($identity)];
        try {
            if ($isEmail) {
                VerificationCode::generateEmailVerificationFor($user, 'fleetops_customer_password_reset', [
                    'subject'         => config('app.name') . ' password reset',
                    'messageCallback' => fn ($v) => 'Your ' . config('app.name') . ' password reset code is ' . $v->code,
                    'meta'            => $meta,
                ]);
            } else {
                VerificationCode::generateSmsVerificationFor($user, 'fleetops_customer_password_reset', [
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

        $isEmail = Utils::isEmail($identity);
        $needle  = $isEmail ? $identity : static::phone($identity);

        $verificationCode = VerificationCode::where([
            'code'           => $code,
            'for'            => 'fleetops_customer_password_reset',
            'meta->identity' => $needle,
        ])->first();
        if (!$verificationCode) {
            return response()->apiError('Invalid reset code.');
        }

        $user = $isEmail
            ? User::where('email', $needle)->first()
            : User::where('phone', $needle)->first();
        if (!$user) {
            return response()->apiError('Account not found.');
        }

        // setPasswordAttribute mutator hashes plaintext; don't pre-hash here.
        $user->password = $password;
        $user->save();
        // Invalidate all existing sessions for this user after a password reset.
        $user->tokens()->delete();
        $verificationCode->delete();

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
        $customer = CustomerAuth::current();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        return new CustomerResource($customer);
    }

    /**
     * Update the authenticated customer's profile (Contact + linked User fields).
     */
    public function updateMe(UpdateContactRequest $request)
    {
        $customer = CustomerAuth::current();
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
            if (Utils::isPublicId($photo)) {
                $file = File::where('public_id', $photo)->first();
                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }
            if (Utils::isBase64String($photo)) {
                $path = implode('/', ['uploads', session('company'), 'customers']);
                $file = File::createFromBase64($photo, null, $path);
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
                'name'  => $input['name']  ?? null,
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
            ], fn ($v) => $v !== null);
            if (!empty($userUpdate)) {
                User::where('uuid', $customer->user_uuid)->update($userUpdate);
            }
        }

        return new CustomerResource($customer->fresh());
    }

    /**
     * Revoke the Customer-Token used on this request.
     */
    public function logout(Request $request)
    {
        $customer = CustomerAuth::current();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        $tokenString = $request->header(CustomerAuth::HEADER);
        $accessToken = $tokenString ? \Laravel\Sanctum\PersonalAccessToken::findToken($tokenString) : null;
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
        $customer = CustomerAuth::current();
        if (!$customer || !$customer->user_uuid) {
            return response()->apiError('Not authenticated.', 401);
        }

        $user = User::where('uuid', $customer->user_uuid)->first();
        if ($user) {
            $user->tokens()->delete();
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * List the authenticated customer's orders.
     */
    public function orders(Request $request)
    {
        $customer = CustomerAuth::current();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        $results = Order::queryWithRequest($request, function (&$query) use ($customer) {
            $query->where('customer_uuid', $customer->uuid)
                ->whereNull('deleted_at')
                ->withoutGlobalScopes();
        });

        return OrderResource::collection($results);
    }

    /**
     * Fetch one order owned by the authenticated customer.
     */
    public function findOrder(string $id)
    {
        $customer = CustomerAuth::current();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->apiError('Order not found.', 404);
        }

        if ($order->customer_uuid !== $customer->uuid) {
            return response()->apiError('Order not found.', 404);
        }

        return new OrderResource($order);
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
        $customer = CustomerAuth::current();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        $sessionCompany = session('company');
        if (!$sessionCompany) {
            return response()->apiError('No company resolved from API credential.', 500);
        }

        // Resolve the order config for this order. Mirrors OrderController::create.
        $orderConfig = OrderConfig::resolveFromIdentifier($request->only(['type', 'order_config']))
            ?: OrderConfig::where('company_uuid', $sessionCompany)->first();
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
            $payloadUuid = Utils::getUuid('payloads', [
                'public_id'    => $request->input('payload'),
                'company_uuid' => $sessionCompany,
            ]);
        } else {
            $payloadInput = $request->only(['pickup', 'dropoff', 'return', 'waypoints', 'entities']);
            if (array_filter($payloadInput, fn ($v) => $v !== null && $v !== '' && $v !== [])) {
                $payloadUuid = $this->buildPayloadFromInput($payloadInput, $sessionCompany)->uuid;
            }
        }

        $order = Order::create([
            'company_uuid'      => $sessionCompany,
            'customer_uuid'     => $customer->uuid,
            'customer_type'     => Utils::getModelClassName('contact'),
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
        $serviceQuote = ServiceQuote::resolveFromRequest($request);
        if ($serviceQuote instanceof ServiceQuote) {
            $order->purchaseServiceQuote($serviceQuote);
        }

        return new OrderResource($order->fresh(['payload', 'payload.pickup', 'payload.dropoff', 'payload.entities']));
    }

    /**
     * Build a Payload from the canonical {pickup, dropoff, return, waypoints,
     * entities} shape. Identical to OrderController::create's payload-building
     * branch so customer-created orders are indistinguishable from operator-
     * created ones at the data layer.
     */
    protected function buildPayloadFromInput(array $payloadInput, string $companyUuid): Payload
    {
        $payload   = new Payload();
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
        $customer = CustomerAuth::current();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        $results = Place::queryWithRequest($request, function (&$query) use ($customer) {
            $query->where('owner_uuid', $customer->uuid);
        });

        return PlaceResource::collection($results);
    }

    /**
     * Register a push-notification device for the authenticated customer's user.
     */
    public function registerDevice(Request $request)
    {
        $customer = CustomerAuth::current();
        if (!$customer) {
            return response()->apiError('Not authenticated.', 401);
        }

        $device = UserDevice::firstOrCreate(
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
