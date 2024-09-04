<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Invite;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Notifications\UserInvited;
use Fleetbase\Traits\HasApiModelBehavior;
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
    use HasMetaAttributes;
    use HasInternalId;
    use TracksApiCredential;
    use Searchable;
    use SendsWebhooks;
    use HasSlug;
    use LogsActivity;
    use CausesActivity;
    use Notifiable;

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
    protected $fillable = ['_key', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'place_uuid', 'photo_uuid', 'name', 'title', 'email', 'phone', 'type', 'meta', 'slug'];

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
     * @param bool    $sendInvite (optional) Whether to send an invitation to the newly created user. Default is true.
     *
     * @return User the newly created user instance
     *
     * @throws UserAlreadyExistsException if a user with the same email or phone number already exists
     */
    public static function createUserFromContact(Contact $contact, bool $sendInvite = true): User
    {
        // Check if user already exist with email or phone number
        $userAlreadyExists = User::where('type', $contact->type)->where(function ($query) use ($contact) {
            $query->where('email', $contact->email);
            $query->orWhere('phone', $contact->phone);
        })->first();
        if ($userAlreadyExists) {
            throw new UserAlreadyExistsException('User already exists, try to assign the user to this contact.');
        }

        // Load company
        $contact->loadMissing('company');

        // Create the user record
        $user = User::create([
            'company_uuid' => $contact->company_uuid,
            'name'         => $contact->name,
            'email'        => $contact->email,
            'phone'        => $contact->phone,
            'username'     => Str::slug($contact->name . '_' . Str::random(4), '_'),
            'password'     => Str::random(),
            'timezone'     => $contact->company->timezone ?? date_default_timezone_get(),
            'status'       => 'pending',
        ]);

        // Set user type
        $user->setType($contact->type);

        // Assing to company
        $user->assignCompany($contact->company);

        // Assign customer role
        if ($user->type === 'customer') {
            $user->assignSingleRole('Fleet-Ops Customer');
        }

        // Set user to contact
        $contact->update(['user_uuid' => $user->uuid]);

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

        return $user;
    }

    /**
     * Creates a new user from the current contact instance and optionally sends an invitation.
     *
     * This method is a wrapper around the createUserFromContact method. It allows creating a user directly
     * from a contact instance and, optionally, sending an invitation to the newly created user.
     *
     * @param bool $sendInvite (optional) Whether to send an invitation to the newly created user. Default is true.
     *
     * @return User the newly created user instance
     */
    public function createUser(bool $sendInvite = true): User
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
}
