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
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
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

        $input = [
            'type'         => 'customer',
            'company_uuid' => $sessionCompany,
            'user_uuid'    => $user->uuid,
            'name'         => $request->input('name') ?: $user->name,
            'title'        => $request->input('title'),
            'email'        => $isEmail ? $identity : ($request->input('email') ?: $user->email),
            'phone'        => $isEmail ? static::phone($request->input('phone')) : $identity,
            'meta'         => array_merge(
                ['origin' => 'fleetops_customer_portal'],
                (array) $request->input('meta', [])
            ),
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

        // If the signup included home-address fields (either nested under meta or
        // sent as a top-level `address` object), materialize them as a Place
        // owned by the new customer and set as their default. Idempotent: only
        // creates a Place when none is already linked.
        $address = $request->input('address') ?? data_get($input, 'meta.address');
        if (is_array($address) && !$contact->place_uuid) {
            $place = $this->createCustomerPlace($contact, $sessionCompany, $address);
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
     * Materialize a customer's address payload into a Place owned by the
     * Contact (polymorphic via `owner_uuid` + `owner_type`).
     *
     * Accepts both Storefront-style (street1/street2/city/province/postal_code/
     * country) and portal-form-style (line1/line2/state/zip) keys.
     */
    protected function createCustomerPlace(Contact $contact, string $companyUuid, array $address): ?Place
    {
        $street1     = data_get($address, 'street1') ?? data_get($address, 'line1');
        $street2     = data_get($address, 'street2') ?? data_get($address, 'line2');
        $city        = data_get($address, 'city');
        $province    = data_get($address, 'province') ?? data_get($address, 'state');
        $postalCode  = data_get($address, 'postal_code') ?? data_get($address, 'zip');
        $country     = data_get($address, 'country');
        $placeName   = data_get($address, 'name') ?: trim(($contact->name ?: 'Customer') . ' — Home');

        // Skip when there's nothing usable to save.
        if (!$street1 && !$city && !$province && !$postalCode) {
            return null;
        }

        return Place::create([
            'company_uuid' => $companyUuid,
            'owner_uuid'   => $contact->uuid,
            'owner_type'   => get_class($contact),
            'name'         => $placeName,
            'type'         => 'residential',
            'street1'      => $street1,
            'street2'      => $street2,
            'city'         => $city,
            'province'     => $province,
            'postal_code'  => $postalCode,
            'country'      => $country,
            'phone'        => $contact->phone,
        ]);
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
                'meta'  => ['origin' => 'fleetops_customer_portal'],
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
                'meta'  => ['origin' => 'fleetops_customer_portal'],
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
     * Create a freight order on behalf of the authenticated customer.
     *
     * Accepts a lightweight portal-friendly body (item + weight + value + mode
     * + pickup/dropoff) and maps it into a full Fleet-Ops Order (Payload +
     * Entity + Places) under the company resolved from the API credential.
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

        // Resolve the default order config for the company; fall back to creating
        // one if none exists yet (matches OrderController behavior expectations).
        $orderConfig = OrderConfig::resolveFromIdentifier($request->only(['type', 'order_config']))
            ?: OrderConfig::where('company_uuid', $sessionCompany)->first();
        if (!$orderConfig) {
            return response()->apiError('No order config found for this company.', 422);
        }

        // Resolve pickup/dropoff Places.
        $pickup  = $this->resolvePlace($request->input('pickup'), $sessionCompany);
        $dropoff = $this->resolvePlace($request->input('dropoff'), $sessionCompany);

        // Create the payload.
        $payload = Payload::create([
            'company_uuid' => $sessionCompany,
            'pickup_uuid'  => $pickup?->uuid,
            'dropoff_uuid' => $dropoff?->uuid,
        ]);

        // Create the entity (the item being shipped).
        Entity::create([
            'company_uuid'   => $sessionCompany,
            'payload_uuid'   => $payload->uuid,
            'name'           => $request->input('item'),
            'description'    => $request->input('category'),
            'weight'         => $request->input('weight'),
            'weight_unit'    => $request->input('weight_unit', 'lb'),
            'declared_value' => $request->input('value'),
            'currency'       => $request->input('currency', 'USD'),
            'destination_uuid' => $dropoff?->uuid,
            'meta'           => [
                'mode' => $request->input('mode', 'Ocean'),
            ],
        ]);

        // Create the order itself.
        $order = Order::create([
            'company_uuid'      => $sessionCompany,
            'customer_uuid'     => $customer->uuid,
            'customer_type'     => Utils::getModelClassName('contact'),
            'payload_uuid'      => $payload->uuid,
            'order_config_uuid' => $orderConfig->uuid,
            'type'              => $orderConfig->key,
            'status'            => 'created',
            'scheduled_at'      => $request->input('scheduled_at'),
            'notes'             => $request->input('notes'),
            'meta'              => array_merge(
                [
                    'mode'              => $request->input('mode', 'Ocean'),
                    'delivery_required' => (bool) $request->input('delivery', false),
                    'origin'            => 'fleetops_customer_portal',
                ],
                (array) $request->input('meta', [])
            ),
        ]);

        return new OrderResource($order->fresh(['payload', 'payload.pickup', 'payload.dropoff', 'payload.entities']));
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
     * Resolve a Place from a free-form payload (either a public_id reference
     * or an inline address object). Returns null when nothing was supplied.
     */
    protected function resolvePlace($input, string $companyUuid): ?Place
    {
        if (empty($input)) {
            return null;
        }

        // Public-id reference: resolve in-place.
        if (is_string($input)) {
            return Place::where(['public_id' => $input, 'company_uuid' => $companyUuid])->first();
        }

        if (!is_array($input)) {
            return null;
        }

        if (isset($input['place']) && is_string($input['place'])) {
            $existing = Place::where(['public_id' => $input['place'], 'company_uuid' => $companyUuid])->first();
            if ($existing) {
                return $existing;
            }
        }

        return Place::create([
            'company_uuid' => $companyUuid,
            'name'         => $input['name']        ?? null,
            'street1'      => $input['street1']     ?? null,
            'street2'      => $input['street2']     ?? null,
            'city'         => $input['city']        ?? null,
            'province'     => $input['province']    ?? null,
            'postal_code'  => $input['postal_code'] ?? null,
            'country'      => $input['country']     ?? null,
        ]);
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
