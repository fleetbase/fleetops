<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Exceptions\CustomerUserConflictException;
use Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Invite;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Notifications\UserInvited;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasCustomFields;
use Fleetbase\Traits\HasInternalId;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\CausesActivity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Contact extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasApiModelCache;
    use HasMetaAttributes;
    use HasInternalId;
    use TracksApiCredential;
    use Searchable;
    use SendsWebhooks;
    use HasSlug;
    use LogsActivity;
    use CausesActivity;
    use Notifiable;
    use HasCustomFields;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'contacts';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'contact';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'email', 'phone'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        '_key',
        'public_id',
        'internal_id',
        'company_uuid',
        'user_uuid',
        'place_uuid',
        'photo_uuid',
        'name',
        'title',
        'email',
        'phone',
        'type',
        'notes',
        'meta',
        'slug',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta' => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['photo_url'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['photo'];

    /**
     * Filterable attributes/parameters.
     *
     * @var array
     */
    protected $filterParams = ['place_uuid', 'customer_type', 'facilitator_type', 'place'];

    /**
     * The session-agnostic columns for the model.
     *
     * @var array
     */
    protected $sessionAgnosticColumns = ['user_uuid'];

    /**
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['*'])->logOnlyDirty();
    }

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid');
    }

    public function anyUser(): BelongsTo|Builder
    {
        return $this->belongsTo(User::class, 'user_uuid');
    }

    public function user(): BelongsTo|Builder
    {
        return $this->belongsTo(User::class, 'user_uuid')->where('type', $this->type);
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(\Fleetbase\Models\File::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'place_uuid');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(\Fleetbase\Models\UserDevice::class, 'user_uuid', 'user_uuid');
    }

    public function places(): HasMany
    {
        return $this->hasMany(Place::class, 'owner_uuid');
    }

    public function facilitatorOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'facilitator_uuid', 'uuid');
    }

    public function customerOrders(): HasMany|Builder
    {
        return $this->hasMany(Order::class, 'customer_uuid')->whereNull('deleted_at')->withoutGlobalScopes();
    }

    public function files(): HasMany
    {
        return $this->hasMany(\Fleetbase\Models\File::class, 'subject_uuid')->latest();
    }

    /**
     * Specifies the user's FCM tokens.
     */
    public function routeNotificationForFcm(): array
    {
        return $this->devices
            ->where('platform', 'android')
            ->map(
                function ($userDevice) {
                    return $userDevice->token;
                }
            )->toArray();
    }

    /**
     * Specifies the user's APNS tokens.
     */
    public function routeNotificationForApn(): array
    {
        return $this->devices
            ->where('platform', 'ios')
            ->map(
                function ($userDevice) {
                    return $userDevice->token;
                }
            )->toArray();
    }

    /**
     * The attribute to route notifications to.
     */
    public function routeNotificationForTwilio(): ?string
    {
        return $this->phone;
    }

    public function setPhoneAttribute(?string $value)
    {
        $this->attributes['phone'] = is_string($value) ? Utils::formatPhoneNumber($value) : $value;
    }

    /**
     * Get avatar URL attribute.
     */
    public function getPhotoUrlAttribute(): string
    {
        return data_get($this, 'photo.url', 'https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png');
    }

    /**
     * The number of orders by this user.
     */
    public function getCustomerOrdersCountAttribute(): int
    {
        return $this->customerOrders()->count();
    }

    /**
     * Determines if user is a customer.
     */
    public function getIsCustomerAttribute(): bool
    {
        return $this->type === 'customer';
    }

    /**
     * Determines if this contact is a Fleet-Ops customer.
     */
    public function isCustomer(): bool
    {
        return $this->type === 'customer';
    }

    /**
     * Creates a new contact from an import row.
     */
    public static function createFromImport(array $row, bool $saveInstance = false): Contact
    {
        // Filter array for null key values
        $row = array_filter($row);

        // Get contact columns
        $name  = Utils::or($row, ['name', 'full_name', 'first_name', 'contact', 'person']);
        $phone = Utils::or($row, ['phone', 'mobile', 'phone_number', 'number', 'cell', 'cell_phone', 'mobile_number', 'contact_number', 'tel', 'telephone', 'telephone_number']);
        $email = Utils::or($row, ['email', 'email_address']);

        // Create contact
        $contact = new static([
            'company_uuid' => session('company'),
            'name'         => $name,
            'phone'        => Utils::fixPhone($phone),
            'email'        => $email,
            'type'         => 'contact',
        ]);

        if ($saveInstance === true) {
            $contact->save();
        }

        return $contact;
    }

    /**
     * Creates a new user from the given contact and optionally sends an invitation.
     *
     * This method first checks if a user with the same email or phone number as the contact already exists.
     * If such a user exists, it throws a UserAlreadyExistsException. Otherwise, it proceeds to create a new
     * user record using the contact's details, assigns the user to the contact's company, and links the
     * user to the contact. Optionally, it sends an invitation to the newly created user via email.
     *
     * @param Contact $contact    the contact instance from which the user should be created
     * @param bool    $sendInvite (optional) Whether to send an invitation to the newly created user. Default is false.
     *
     * @return User the newly created user instance
     *
     * @throws UserAlreadyExistsException if a user with the same email or phone number already exists
     */
    public static function createUserFromContact(Contact $contact, bool $sendInvite = false, bool $update = false): User
    {
        // Check if user already exist with email or phone number
        $existingUser = null;
        if ($contact->email || $contact->phone) {
            $existingUser = User::where(function ($query) use ($contact) {
                $query->where('company_uuid', $contact->company_uuid)
                    ->orWhereHas('companyUsers', function ($query) use ($contact) {
                        $query->where('company_uuid', $contact->company_uuid);
                    });
            })
                ->where(function ($query) use ($contact) {
                    if ($contact->email) {
                        $query->where('email', $contact->email);
                    }

                    if ($contact->phone) {
                        $method = $contact->email ? 'orWhere' : 'where';
                        $query->{$method}('phone', Utils::formatPhoneNumber($contact->phone));
                    }
                })
                ->whereNull('deleted_at')
                ->first();
        }

        if ($existingUser) {
            if ($contact->isCustomer()) {
                $contact->assertCustomerUserCanBeAssigned($existingUser);
            }

            // Check if existing user belongs to another contact
            $existingUserContact = Contact::where(['user_uuid' => $existingUser->uuid, 'company_uuid' => $contact->company_uuid])->whereHas('user')->first();
            if ($existingUserContact) {
                throw new UserAlreadyExistsException('User already exists, try to assigning the user to this contact.', $existingUser);
            } else {
                // Assign the user to this contact instead
                $contact->setAttribute('user_uuid', $existingUser->uuid);
                if ($update) {
                    $contact->update(['user_uuid' => $existingUser->uuid]);
                }
                $contact->setRelation('user', $existingUser);
            }

            return $existingUser;
        }

        // Load company
        $contact->loadMissing('company');

        // Create the user record
        $user = User::create([
            'company_uuid' => $contact->company_uuid,
            'name'         => $contact->name,
            'email'        => $contact->email,
            'phone'        => $contact->phone ? Utils::formatPhoneNumber($contact->phone) : null,
            'username'     => Str::slug($contact->name . '_' . Str::random(4), '_'),
            'password'     => Str::random(),
            'timezone'     => $contact->company->timezone ?? date_default_timezone_get(),
            'status'       => 'pending',
        ]);

        // Set user type
        $user->setType($contact->type);

        // Assing to company
        $user->assignCompany($contact->company, $contact->isCustomer() ? 'Fleet-Ops Customer' : 'Fleet-Ops Contact');

        // Assign customer role
        if ($contact->isCustomer()) {
            $user->assignSingleRole('Fleet-Ops Customer');
        }

        // Set user to contact
        $contact->setAttribute('user_uuid', $user->uuid);
        if ($update) {
            $contact->update(['user_uuid' => $user->uuid]);
        }

        // Optionally, send invite
        if ($sendInvite) {
            // send invitation to user
            $invitation = Invite::create([
                'company_uuid'    => $user->company_uuid,
                'created_by_uuid' => session('user'),
                'subject_uuid'    => $user->company_uuid,
                'subject_type'    => Utils::getMutationType('company'),
                'protocol'        => 'email',
                'recipients'      => [$user->email],
                'reason'          => 'join_company',
            ]);

            // notify user
            $user->notify(new UserInvited($invitation));
        }

        $contact->setRelation('user', $user);

        return $user;
    }

    /**
     * Normalize the linked user for a customer contact.
     */
    public function normalizeCustomerUser(?User $user = null, bool $quiet = false): ?User
    {
        if (!$this->isCustomer()) {
            return $user;
        }

        $user ??= $this->getUser();
        if (!$user) {
            return null;
        }

        $this->assertCustomerUserCanBeAssigned($user);

        $this->loadMissing('company');
        if ($this->company) {
            $companyUser = CompanyUser::firstOrCreate(
                [
                    'company_uuid' => $this->company->uuid,
                    'user_uuid'    => $user->uuid,
                ],
                [
                    'company_uuid' => $this->company->uuid,
                    'user_uuid'    => $user->uuid,
                    'status'       => $user->status ?? 'active',
                ]
            );
            $user->forceFill(['company_uuid' => $this->company->uuid])->saveQuietly();
            $user->setRelation('companyUser', $companyUser);

            if ($companyUser) {
                $companyUser->assignSingleRole('Fleet-Ops Customer');
            }
        }

        $this->setRelation('user', $user);

        return $user;
    }

    /**
     * Repair a historical customer contact and its linked user.
     */
    public function repairCustomerTypeInvariant(bool $quiet = false): ?User
    {
        if (!$this->isCustomer()) {
            return $this->getUser();
        }

        $user = $this->getUser();
        if (!$user && ($this->email || $this->phone)) {
            if ($quiet) {
                $this->loadMissing('company');
                $user = User::create([
                    'company_uuid' => $this->company_uuid,
                    'name'         => $this->name,
                    'email'        => $this->email,
                    'phone'        => $this->phone ? Utils::formatPhoneNumber($this->phone) : null,
                    'username'     => Str::slug($this->name . '_' . Str::random(4), '_'),
                    'password'     => Str::random(),
                    'timezone'     => $this->company->timezone ?? date_default_timezone_get(),
                    'status'       => 'pending',
                ]);
                $user->forceFill(['type' => 'customer'])->saveQuietly();
                $this->forceFill(['user_uuid' => $user->uuid])->saveQuietly();
            } else {
                $user = $this->createUser();
            }
        }

        return $this->normalizeCustomerUser($user, $quiet);
    }

    /**
     * Assigns a user to the company and optionally sends an invitation email.
     *
     * This method performs the following actions:
     * 1. Associates the provided user with the current company, assigning them a role based on the
     *    company's type (e.g., 'Fleet-Ops Customer' or 'Fleet-Ops Contact').
     * 2. If the user is of type 'customer', assigns the 'Fleet-Ops Customer' role specifically.
     * 3. Updates the current entity's `user_uuid` to reference the assigned user.
     * 4. Optionally sends an invitation email to the user, inviting them to join the company.
     *
     * @param User $user       the user to be assigned to the company
     * @param bool $sendInvite Determines whether to send an invitation email to the user. Defaults to false.
     *
     * @return self returns the current instance to allow method chaining
     *
     * @throws \Exception if an error occurs during user assignment or while sending the invitation
     */
    public function assignUser(User $user, bool $sendInvite = false): self
    {
        // Load company
        $this->loadMissing('company');

        if ($this->isCustomer()) {
            $this->assertCustomerUserCanBeAssigned($user);

            $existingUserContact = static::where(['user_uuid' => $user->uuid, 'company_uuid' => $this->company_uuid])
                ->whereNot('uuid', $this->uuid)
                ->whereHas('user')
                ->first();

            if ($existingUserContact) {
                throw new UserAlreadyExistsException('User already exists, try to assigning the user to this contact.', $user);
            }
        }

        // Assing to company
        $user->assignCompany($this->company, $this->isCustomer() ? 'Fleet-Ops Customer' : 'Fleet-Ops Contact');

        // Get the company user instance
        $companyUser = $user->getCompanyUser($this->company);

        if ($companyUser) {
            $companyUser->assignSingleRole($this->isCustomer() ? 'Fleet-Ops Customer' : 'Fleet-Ops Contact');
        }

        // Set user to contact
        $this->update(['user_uuid' => $user->uuid]);

        // Optionally, send invite
        if ($sendInvite) {
            // send invitation to user
            $invitation = Invite::create([
                'company_uuid'    => $user->company_uuid,
                'created_by_uuid' => session('user'),
                'subject_uuid'    => $user->company_uuid,
                'subject_type'    => Utils::getMutationType('company'),
                'protocol'        => 'email',
                'recipients'      => [$user->email],
                'reason'          => 'join_company',
            ]);

            // notify user
            $user->notify(new UserInvited($invitation));
        }

        $this->setRelation('user', $user);

        return $this;
    }

    public function assertCustomerIdentityIsAvailable(): void
    {
        if (!$this->isCustomer()) {
            return;
        }

        $assignedUser = $this->getAssignedUserWithoutTypeScope();
        if ($assignedUser) {
            $this->assertCustomerUserCanBeAssigned($assignedUser);
        }

        $matchedUser = $this->findExistingUserByIdentity();
        if ($matchedUser && $matchedUser->uuid !== $this->user_uuid) {
            $this->assertCustomerUserCanBeAssigned($matchedUser);
        }
    }

    public function assertCustomerUserCanBeAssigned(User $user): void
    {
        if ($user->type === 'customer') {
            return;
        }

        throw new CustomerUserConflictException(static::customerUserConflictMessage($user), $user);
    }

    public static function customerUserConflictMessage(?User $user = null): string
    {
        $field = $user?->email ? 'email' : ($user?->phone ? 'phone number' : 'user account');

        return 'This ' . $field . ' belongs to an existing staff user and cannot be used for a customer account.';
    }

    private function findExistingUserByIdentity(): ?User
    {
        if (!$this->email && !$this->phone) {
            return null;
        }

        return User::where(function ($query) {
            $query->where('company_uuid', $this->company_uuid)
                ->orWhereHas('companyUsers', function ($query) {
                    $query->where('company_uuid', $this->company_uuid);
                });
        })
            ->where(function ($query) {
                if ($this->email) {
                    $query->where('email', $this->email);
                }

                if ($this->phone) {
                    $method = $this->email ? 'orWhere' : 'where';
                    $query->{$method}('phone', Utils::formatPhoneNumber($this->phone));
                }
            })
            ->whereNull('deleted_at')
            ->first();
    }

    private function getAssignedUserWithoutTypeScope(): ?User
    {
        if (!Str::isUuid($this->user_uuid)) {
            return null;
        }

        return User::where('uuid', $this->user_uuid)->first();
    }

    public function syncWithUser(): bool
    {
        $updates = [];

        if ($this->isDirty('name')) {
            $updates['name'] = $this->name;
        }

        if ($this->isDirty('email')) {
            $updates['email'] = $this->email;
        }

        if ($this->isDirty('phone')) {
            $updates['phone'] = $this->phone;
        }

        if ($this->isDirty('timezone')) {
            $updates['timezone'] = $this->timezone;
        }

        $user = $this->getUser();
        if ($user) {
            return $user->update($updates);
        }

        return false;
    }

    /**
     * Creates a new user from the current contact instance and optionally sends an invitation.
     *
     * This method is a wrapper around the createUserFromContact method. It allows creating a user directly
     * from a contact instance and, optionally, sending an invitation to the newly created user.
     *
     * @param bool $sendInvite (optional) Whether to send an invitation to the newly created user. Default is false.
     *
     * @return User the newly created user instance
     */
    public function createUser(bool $sendInvite = false): User
    {
        return static::createUserFromContact($this, $sendInvite);
    }

    /**
     * Deletes the contact's assosciated user.
     */
    public function deleteUser(): ?bool
    {
        $this->loadMissing('user');
        if ($this->user && $this->user->type === $this->type) {
            return $this->user->delete();
        }

        return false;
    }

    public function getUser(): ?User
    {
        $this->loadMissing('user');
        if ($this->user) {
            return $this->user;
        }

        if (Str::isUuid($this->user_uuid)) {
            return User::where('uuid', $this->user_uuid)->first();
        }

        return null;
    }

    public function hasUser(): bool
    {
        $user = $this->getUser();

        return Str::isUuid($this->user_uuid) || $user instanceof User;
    }

    public function doesntHaveUser(): bool
    {
        return !$this->hasUser();
    }
}
