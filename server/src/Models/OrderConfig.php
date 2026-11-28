<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Casts\OrderConfigEntities;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Support\AdhocDispatch\AdhocDispatch;
use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigPolicies;
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
        'policies',
        'triggers',
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
        'policies' => Json::class,
        'triggers' => Json::class,
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

        static::saving(fn ($model) => $model->normalizePolicies());
    }

    /**
     * Keep stored policies valid: drop an adhoc strategy that isn't registered, and keep the
     * offer timeout within its limits.
     */
    public function normalizePolicies(): void
    {
        if (!is_array($this->policies)) {
            return;
        }

        $policies = $this->policies;

        $strategy = data_get($policies, 'dispatch.adhoc.strategy');
        if ($strategy !== null && !AdhocDispatch::has($strategy)) {
            data_set($policies, 'dispatch.adhoc.strategy', null);
        }

        $timeout = data_get($policies, 'dispatch.adhoc.offer_timeout');
        if ($timeout !== null && $timeout !== '') {
            data_set($policies, 'dispatch.adhoc.offer_timeout', max(AdhocDispatch::MIN_OFFER_TIMEOUT, min(AdhocDispatch::MAX_OFFER_TIMEOUT, (int) $timeout)));
        }

        $this->policies = $policies;
    }

    /**
     * Every policy setting, with each one the config doesn't set taken from its default.
     */
    public function resolvedPolicies(): array
    {
        return OrderConfigPolicies::resolve(is_array($this->policies) ? $this->policies : []);
    }

    /**
     * One policy setting, in dot notation (`dispatch.adhoc.strategy`), or a whole policy
     * (`dispatch.adhoc`). The registered default applies when the config doesn't set it.
     */
    public function policy(string $key, mixed $default = null): mixed
    {
        $value = data_get($this->resolvedPolicies(), $key);

        return $value ?? $default;
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
     * Check an activity update against what the config declares, for every path that moves an
     * order (console, API, driver app). Returns the activity to apply, which is the config's own
     * definition when transitions are strict, and the reason the update is refused, or null.
     *
     * - a finished order takes no more activities, unless `$fromTerminal` (operator corrections)
     * - strict transitions only allow a configured child of the current activity
     * - a config whose lifecycle turns dispatch off refuses `dispatched`
     * - an activity that needs an inspection (`require_inspection`, with the form in
     *   `inspection_form`) needs `$inspection`, a submitted inspection of that form filed for the
     *   order that did not find the vehicle unsafe, unless `$bypassInspection`
     * - an activity that needs proof of delivery (`require_pod`, or completing an order that
     *   requires proof) needs proof, unless `$bypassProof`
     *
     * @return array{0: Activity, 1: ?string}
     */
    public function authorizeTransition(Order $order, Activity $activity, mixed $proof = null, bool $bypassProof = false, bool $fromTerminal = false, mixed $inspection = null, bool $bypassInspection = false): array
    {
        if (!$fromTerminal && $this->isTerminalActivityCode($order->status)) {
            return [$activity, 'This order has already finished.'];
        }

        if ($this->hasStrictTransitions()) {
            $targetCode = $activity->get('code');
            $configured = $targetCode ? $this->getActivityByCode($targetCode) : null;
            if (!$configured || !$this->canTransition($order->status, $targetCode)) {
                return [$activity, 'This activity is not a permitted next step for the order.'];
            }

            $activity = $configured;
        }

        if ($activity->is('dispatched') && !$this->allowsDispatch()) {
            return [$activity, 'Orders of this type are not dispatched.'];
        }

        // The inspection comes before proof, as the app asks for them: a step held by an unsafe
        // vehicle should say so, not ask for a photo first.
        $inspectionRefusal = $this->inspectionRefusal($order, $activity, $inspection, $bypassInspection);
        if ($inspectionRefusal) {
            return [$activity, $inspectionRefusal];
        }

        $requiresProof = $activity->get('require_pod') || ($activity->completesOrder() && $order->pod_required);
        if ($requiresProof && !$proof && !$bypassProof) {
            return [$activity, 'Proof of delivery is required for this activity or must be explicitly bypassed.'];
        }

        return [$activity, null];
    }

    /**
     * The activity's inspection requirement, as the config declares it.
     *
     * Without strict transitions the activity is the one the client sent, which may have lost
     * `require_inspection` on the way (a client that sends only the code, or an older app). The
     * config's own activity of the same code is asked first, so leaving the flag off, or naming
     * another form, does not get a step past its inspection.
     */
    public function inspectionActivityFor(Activity $activity): ?Activity
    {
        $code       = $activity->get('code');
        $configured = $code ? $this->getActivityByCode($code) : null;
        if ($configured && $configured->requiresInspection()) {
            return $configured;
        }

        return $activity->requiresInspection() ? $activity : null;
    }

    /**
     * Why a step that needs an inspection is refused, or null when it may go ahead.
     *
     * `$inspection` is the id (public id or uuid) of the submission the driver filed for it. It must
     * belong to the order's company, be of the form the step names, be submitted rather than a
     * draft and be for this order. One that found the vehicle unsafe holds the order at the step,
     * and dispatch is told. `$bypassInspection` lets an operator move the order on from the
     * console without one, as `bypass_proof` does for proof.
     */
    protected function inspectionRefusal(Order $order, Activity $activity, mixed $inspection, bool $bypassInspection): ?string
    {
        $required = $this->inspectionActivityFor($activity);
        if (!$required || $bypassInspection) {
            return null;
        }

        $id = is_array($inspection) ? (data_get($inspection, 'id') ?? data_get($inspection, 'uuid')) : $inspection;
        if (!is_string($id) || trim($id) === '') {
            return 'An inspection is required for this activity or must be explicitly bypassed.';
        }

        $submission = InspectionSubmission::query()
            ->where('company_uuid', $order->company_uuid)
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
        if (!$submission) {
            return 'The inspection for this activity was not found.';
        }

        // A step whose form has since been deleted takes any inspection filed for the order: the
        // driver cannot file a form that no longer exists, and dispatch can still read what was.
        $form = $required->inspectionForm($order->company_uuid);
        if ($form && $submission->inspection_form_uuid !== $form->uuid) {
            return sprintf('This activity needs the %s inspection; the one sent was filed on a different form.', $form->name ?? 'configured');
        }

        if (in_array($submission->status, ['draft', null, ''], true) || !$submission->submitted_at) {
            return 'The inspection for this activity has not been submitted yet.';
        }

        if (!$submission->isForOrder($order)) {
            return 'The inspection sent was not filed for this order.';
        }

        if ($submission->foundUnsafe()) {
            $submission->notifyDispatchIfUnsafe($order, $required->get('code'));

            return 'The inspection found the vehicle unsafe, so this activity cannot go ahead; dispatch has been told.';
        }

        return null;
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
