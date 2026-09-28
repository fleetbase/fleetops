<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Casts\OrderConfigEntities;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\Models\Company;
use Fleetbase\Models\Model;
use Fleetbase\Support\Auth;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OrderConfig extends Model
{
    use HasUuid;
    use HasPublicId;
    use Searchable;
    use HasMetaAttributes;
    use HasApiModelBehavior;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'order_configs';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'order_config';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'public_id',
        'company_uuid',
        'author_uuid',
        'category_uuid',
        'icon_uuid',
        'name',
        'namespace',
        'description',
        'key',
        'status',
        'version',
        'core_service',
        'tags',
        'flow',
        'entities',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'tags'     => Json::class,
        'flow'     => Json::class,
        'entities' => OrderConfigEntities::class,
        'meta'     => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['type'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The current order in context to this config.
     *
     * @var Order
     */
    protected $orderContext;

    /**
     * Bootstraps the model and its events.
     *
     * This method overrides the default Eloquent model boot method
     * to add a custom 'creating' event listener. This listener is used
     * to set default values when a new model instance is being created.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->namespace = empty($model->namespace) ? static::createNamespace($model->name) : $model->namespace;
            $model->version   = '0.0.1';
            $model->status    = 'private';
            $model->key       = Str::slug($model->name);
        });
    }

    /**
     * Creates a namespaced string based on the provided name.
     *
     * This method generates a namespaced string using the company's name
     * retrieved from the authenticated user's company, followed by a fixed
     * segment ':order-config:', and the provided name. This is used to
     * create a unique namespace for each model instance.
     *
     * @param string $name the name to be included in the namespace
     *
     * @return string the generated namespaced string
     */
    public static function createNamespace(string $name): string
    {
        $company = Auth::getCompany();

        return Str::slug($company->name) . ':order-config:' . Str::slug($name);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function author()
    {
        return $this->belongsTo(\Fleetbase\Models\User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(\Fleetbase\Models\Category::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function icon()
    {
        return $this->belongsTo(\Fleetbase\Models\File::class);
    }

    /**
     * Get all custom fields defined for this order config.
     * CustomField uses a polymorphic subject relationship (subject_uuid / subject_type).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function customFields()
    {
        return $this->morphMany(\Fleetbase\Models\CustomField::class, 'subject', 'subject_type', 'subject_uuid');
    }

    /**
     * Accessor method for getting the type attribute of the order config.
     *
     * @return string the type of the order config
     */
    public function getTypeAttribute()
    {
        return 'order-config';
    }

    /**
     * Sets the order context for the current order config.
     *
     * @param Order $order the order to set as the context
     *
     * @return self returns the instance for chaining
     */
    public function setOrderContext(Order $order): self
    {
        $this->orderContext = $order;

        return $this;
    }

    /**
     * Retrieves the current order context, setting it if not already set.
     *
     * @return Order|null the current order context
     *
     * @throws \Exception if no order context is found and none is provided
     */
    public function getOrderContext(Order|Waypoint|null $context = null): ?Order
    {
        if (!$this->orderContext && $context instanceof Order) {
            $this->setOrderContext($context);

            return $context;
        }

        if (!$this->orderContext && $context instanceof Waypoint) {
            $order = Order::where('payload_uuid', $context->payload_uuid)->first();
            if ($order) {
                $this->setOrderContext($order);

                return $order;
            }
        }

        if (!$this->orderContext) {
            throw new \Exception('No order context found to run order config.');
        }

        return $this->orderContext;
    }

    /**
     * Retrieves all activities defined in the order config's flow.
     *
     * @return Collection a collection of Activity objects
     */
    public function activities(): Collection
    {
        $activities = collect();
        foreach ($this->flow as $activity) {
            $activities->push(new Activity($activity, $this->flow));
        }

        return $activities;
    }

    /**
     * Retrieves the 'created' activity from the flow.
     *
     * @return Activity|null the created Activity object or null if not found
     */
    public function getCreatedActivity(): ?Activity
    {
        return $this->activities()->firstWhere('code', 'created');
    }

    /**
     * Retrieves the 'dispatched' activity from the flow.
     *
     * @return Activity|null the dispatched Activity object or null if not found
     */
    public function getDispatchActivity(): ?Activity
    {
        return $this->activities()->firstWhere('code', 'dispatched');
    }

    /**
     * Determines the current activity based on the order's status.
     *
     * @return Activity|null the current Activity object or null if not found
     */
    public function currentActivity(Order|Waypoint|null $context = null): ?Activity
    {
        if ($context === null) {
            $context = $this->getOrderContext($context);
        }

        if ($context instanceof Waypoint) {
            return $this->activities()->firstWhere('code', strtolower($context->status_code));
        }

        return $this->activities()->firstWhere('code', $context->status);
    }

    /**
     * Determines the next set of activities based on the current activity.
     *
     * @return Collection a collection of the next activities
     */
    public function nextActivity(Order|Waypoint|null $context = null): Collection
    {
        if ($context === null) {
            $context = $this->getOrderContext($context);
        }

        $currentActivity = $this->currentActivity($context);
        if ($currentActivity) {
            return $currentActivity->getNext($context);
        }

        return collect();
    }

    /**
     * Retrieves the first activity in the next set of activities.
     *
     * @return Activity|null the first Activity object in the next set or null if not found
     */
    public function nextFirstActivity(Order|Waypoint|null $context = null): ?Activity
    {
        if ($context === null) {
            $context = $this->getOrderContext($context);
        }

        $next            = collect();
        $currentActivity = $this->currentActivity($context);
        if ($currentActivity) {
            $next = $currentActivity->getNext($context);
        }

        return $next->first();
    }

    /**
     * Retrieves the activity that follows after the next activity.
     *
     * @return Activity|null the Activity object that follows after the next or null if not found
     */
    public function afterNextActivity(Order|Waypoint|null $context = null): ?Activity
    {
        if ($context === null) {
            $context = $this->getOrderContext($context);
        }

        $afterNext       = collect();
        $nextActivity    = $this->nextFirstActivity($context);
        if ($nextActivity) {
            $afterNext = $nextActivity->getNext($context);
        }

        return $afterNext->first();
    }

    /**
     * Retrieves the previous activities based on the current activity.
     *
     * @return Collection a collection of previous activities
     */
    public function previousActivity(Order|Waypoint|null $context = null): Collection
    {
        if ($context === null) {
            $context = $this->getOrderContext($context);
        }

        $currentActivity = $this->currentActivity($context);
        if ($currentActivity) {
            return $currentActivity->getPrevious($context);
        }

        return collect();
    }

    /**
     * Gets an activity from the order config by it's code.
     */
    public function getActivityByCode(string $code): ?Activity
    {
        return $this->activities()->firstWhere('code', $code);
    }

    /**
     * Returns the configured lifecycle semantics stored under `meta.lifecycle`.
     *
     * A configured lifecycle is opt-in. When absent the default dispatch lifecycle
     * (created -> dispatched -> ... -> completed / canceled) applies unchanged.
     *
     * Supported keys:
     * - `initial` (string)            activity code new orders start at
     * - `completed` (string)          activity code that completes an order
     * - `canceled` (string)           activity code used when an order is canceled
     * - `terminal` (string[])         activity codes after which no transition is allowed
     * - `dispatch` (bool)             false disables dispatch for orders using this config
     * - `strict_transitions` (bool)   true only allows moving to a configured child activity
     */
    public function lifecycle(): array
    {
        $lifecycle = data_get($this->meta, 'lifecycle');

        return is_array($lifecycle) ? $lifecycle : [];
    }

    /**
     * Determines if this config defines its own lifecycle semantics.
     */
    public function hasConfiguredLifecycle(): bool
    {
        return !empty($this->lifecycle());
    }

    /**
     * Returns the activity new orders using this config start at.
     *
     * Without a configured lifecycle this is the `created` activity, if present in the flow.
     */
    public function getInitialActivity(): ?Activity
    {
        $initialCode = data_get($this->lifecycle(), 'initial');
        if ($initialCode) {
            return $this->getActivityByCode($initialCode);
        }

        return $this->getCreatedActivity();
    }

    /**
     * Returns the status code new orders using this config start at.
     */
    public function getInitialStatusCode(): string
    {
        return data_get($this->lifecycle(), 'initial') ?: 'created';
    }

    /**
     * Determines if orders using this config may be dispatched.
     */
    public function allowsDispatch(): bool
    {
        $dispatch = data_get($this->lifecycle(), 'dispatch', true);

        return !in_array($dispatch, [false, 'false', 0, '0'], true);
    }

    /**
     * Determines if activity updates must follow the configured flow graph.
     */
    public function hasStrictTransitions(): bool
    {
        return (bool) data_get($this->lifecycle(), 'strict_transitions', false);
    }

    /**
     * Returns the terminal activity codes of this config.
     */
    public function getTerminalActivityCodes(): array
    {
        if (!$this->hasConfiguredLifecycle()) {
            return ['completed', 'canceled'];
        }

        $terminal = data_get($this->lifecycle(), 'terminal', []);
        $terminal = is_array($terminal) ? $terminal : [];
        foreach (['completed', 'canceled'] as $semantic) {
            $code = data_get($this->lifecycle(), $semantic);
            if ($code) {
                $terminal[] = $code;
            }
        }

        return array_values(array_unique($terminal));
    }

    /**
     * Determines if the given activity code is terminal for this config.
     */
    public function isTerminalActivityCode(?string $code): bool
    {
        return $code !== null && in_array($code, $this->getTerminalActivityCodes(), true);
    }

    /**
     * Determines if the configured flow permits moving from one activity code to another.
     *
     * Only the configured graph is consulted: the target must be listed as a child of the
     * source activity, and terminal activities permit no further transition.
     */
    public function canTransition(?string $fromCode, string $toCode): bool
    {
        if ($fromCode === null || $this->isTerminalActivityCode($fromCode)) {
            return false;
        }

        $from = $this->getActivityByCode($fromCode);
        if (!$from) {
            return false;
        }

        return $from->children()->contains(fn ($activity) => $activity->code === $toCode);
    }

    /**
     * Creates an Activity instance representing a canceled order.
     *
     * This method constructs an Activity object with specific attributes
     * like key, code, status, and details, indicating that the order has been canceled.
     * It utilizes the flow associated with the OrderConfig for this purpose.
     *
     * @return Activity a new Activity instance representing a canceled order
     */
    public function getCanceledActivity()
    {
        $configuredCode = data_get($this->lifecycle(), 'canceled');
        if ($configuredCode && ($configuredActivity = $this->getActivityByCode($configuredCode))) {
            return $configuredActivity;
        }

        $canceledActivity = $this->activities()->firstWhere('code', 'canceled');
        if ($canceledActivity) {
            return $canceledActivity;
        }

        return new Activity([
            'key'      => 'order_canceled',
            'code'     => 'canceled',
            'status'   => 'Order canceled',
            'details'  => 'Order was canceled',
            'complete' => false,
        ], $this->flow);
    }

    /**
     * Creates an Activity instance representing a completed order.
     *
     * This method constructs an Activity object with specific attributes
     * such as key, code, status, and details, indicating that the order has been completed.
     * It leverages the flow associated with the OrderConfig to construct this Activity.
     *
     * @return Activity a new Activity instance representing a completed order
     */
    public function getCompletedActivity()
    {
        $configuredCode = data_get($this->lifecycle(), 'completed');
        if ($configuredCode && ($configuredActivity = $this->getActivityByCode($configuredCode))) {
            return $configuredActivity;
        }

        $completedActivity = $this->activities()->firstWhere('code', 'completed');
        if ($completedActivity) {
            return $completedActivity;
        }

        return new Activity([
            'key'      => 'order_completed',
            'code'     => 'completed',
            'status'   => 'Order completed',
            'details'  => 'Order was completed',
            'complete' => true,
        ], $this->flow);
    }

    /**
     * Creates an Activity instance representing a started order.
     *
     * This method constructs an Activity object with specific attributes
     * such as key, code, status, and details, indicating that the order has been started.
     * It leverages the flow associated with the OrderConfig to construct this Activity.
     *
     * @return Activity a new Activity instance representing a started order
     */
    public function getStartedActivity()
    {
        $startedActivity = $this->activities()->firstWhere('code', 'started');
        if ($startedActivity) {
            return $startedActivity;
        }

        return new Activity([
            'key'      => 'order_started',
            'code'     => 'started',
            'status'   => 'Order started',
            'details'  => 'Order has started',
            'complete' => false,
        ], $this->flow);
    }

    /**
     * Resolves an OrderConfig from a given identifier or an array of identifiers within an optional company context.
     *
     * @param string|array $orderConfigIdentifier the identifier(s) for the OrderConfig (uuid, namespace, public_id, or key)
     * @param Company|null $company               the company context, if any, to narrow down the search
     *
     * @return OrderConfig|null the found OrderConfig or null if none found
     */
    public static function resolveFromIdentifier($orderConfigIdentifier, ?Company $company = null): ?OrderConfig
    {
        $companyUuid  = $company instanceof Company ? $company->uuid : session('company');
        $identifiers  = is_array($orderConfigIdentifier) ? $orderConfigIdentifier : [$orderConfigIdentifier];
        $identifiers  = collect($identifiers)->filter(fn ($identifier) => filled($identifier))->values();

        if ($identifiers->isEmpty()) {
            return static::default($company);
        }

        foreach ($identifiers as $identifier) {
            $query = static::query();

            if ($companyUuid) {
                $query->where('company_uuid', $companyUuid);
            }

            $orderConfig = $query->where(function ($query) use ($identifier) {
                $query->where('uuid', $identifier)
                      ->orWhere('namespace', $identifier)
                      ->orWhere('public_id', $identifier)
                      ->orWhere('key', $identifier);
            })->first();

            if ($orderConfig) {
                return $orderConfig;
            }
        }

        return null;
    }

    /**
     * Get the default order config.
     */
    public static function default(?Company $company = null): ?self
    {
        return static::where(['namespace' => 'system:order-config:transport', 'company_uuid' => $company ? $company->uuid : session('company')])->first();
    }

    /**
     * Get the default transport config, creating it for the company if needed.
     */
    public static function defaultOrCreate(?Company $company = null): ?self
    {
        $company ??= Company::where('uuid', session('company'))->first();

        if (!$company) {
            return static::default($company);
        }

        return static::default($company) ?? FleetOps::createTransportConfig($company);
    }
}
