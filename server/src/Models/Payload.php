<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\Internal\v1\Payload as PayloadResource;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Str;

class Payload extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use TracksApiCredential;
    use HasMetaAttributes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'payloads';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'payload';

    /**
     * Delegate a HTTP resource to use for this model.
     *
     * @var string
     */
    protected $httpResource = PayloadResource::class;

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['_key', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'meta', 'payment_method', 'cod_amount', 'cod_currency', 'cod_payment_method', 'type'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta' => Json::class,
    ];

    /**
     * Relations to load with the model.
     *
     * @var array
     */
    protected $with = ['entities', 'waypoints']; // 'pickup', 'dropoff', 'return',

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['pickup_name', 'dropoff_name'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * Address/name of the dropoff location.
     */
    public function getDropoffNameAttribute()
    {
        $dropoff = $this->getDropoffOrLastWaypoint();

        return $dropoff->address ?? $dropoff->name ?? $dropoff->street1 ?? null;
    }

    /**
     * Address/name of the pickup location.
     */
    public function getPickupNameAttribute()
    {
        $pickup = $this->getPickupOrCurrentWaypoint();

        return $pickup->address ?? $pickup->name ?? $pickup->street1 ?? null;
    }

    /**
     * Entities in the payload.
     */
    public function entities()
    {
        return $this->hasMany(Entity::class);
    }

    /**
     * Waypoint records in the payload.
     */
    public function waypointMarkers()
    {
        return $this->hasMany(Waypoint::class)->with(['place']);
    }

    public function getTotalEntitiesAttribute()
    {
        return $this->entities()->count();
    }

    public function getTotalWaypointsAttribute()
    {
        return $this->waypoints()->count();
    }

    /**
     * The order the payload belongs to.
     */
    public function order()
    {
        return $this->hasOne(Order::class)->without(['payload']);
    }

    /**
     * The address the shipment will be delivered to.
     */
    public function dropoff()
    {
        return $this->belongsTo(Place::class, 'dropoff_uuid')->whereNull('deleted_at')->withoutGlobalScopes();
    }

    /**
     * The address the shipment will be delivered from.
     */
    public function pickup()
    {
        return $this->belongsTo(Place::class, 'pickup_uuid')->whereNull('deleted_at')->withoutGlobalScopes();
    }

    /**
     * The address the shipment will be sent to upon failed delivery.
     */
    public function return()
    {
        return $this->belongsTo(Place::class)->withoutGlobalScopes();
    }

    /**
     * The current waypoint of the payload in progress.
     */
    public function currentWaypoint()
    {
        return $this->belongsTo(Place::class, 'current_waypoint_uuid')->withoutGlobalScopes();
    }

    /**
     * Waypoints between start and end.
     *
     * @return \Illuminate\Database\Eloquent\Concerns\HasManyThrough
     */
    public function waypoints()
    {
        return $this->hasManyThrough(Place::class, Waypoint::class, 'payload_uuid', 'uuid', 'uuid', 'place_uuid')->whereNull('waypoints.deleted_at')->withoutGlobalScopes();
    }

    /**
     * Waypoints between start and end.
     *
     * @return \Illuminate\Database\Eloquent\Concerns\HasManyThrough
     */
    public function waypointsCompleted()
    {
        return $this->hasManyThrough(Place::class, WaypointCompleted::class, 'payload_uuid', 'uuid', 'uuid', 'place_uuid')->withoutGlobalScopes();
    }

    /**
     * Always convert fee and rate to integer before insert.
     */
    public function setCodAmountAttribute($value)
    {
        $this->attributes['cod_amount'] = Utils::numbersOnly($value);
    }

    public function setEntities($entities = [])
    {
        if (empty($entities) || !is_array($entities)) {
            return $this;
        }

        foreach ($entities as $attributes) {
            if (isset($attributes['_import_id'])) {
                $waypoint = $this->waypoints->firstWhere('_import_id', $attributes['_import_id']);

                if ($waypoint) {
                    $attributes['destination_uuid'] = $waypoint->uuid;
                }
            }

            // confirm destination_uuid is indeed a place record
            if (isset($attributes['destination_uuid']) && Place::where('uuid', $attributes['destination_uuid'])->doesntExist()) {
                // search waypoints for search_uuid if any
                $destination = Place::where('meta->search_uuid', $attributes['destination_uuid'])->first();

                if ($destination instanceof Place) {
                    $attributes['destination_uuid'] = $destination->uuid;
                } else {
                    unset($attributes['destination_uuid']);
                }
            }

            $entity = new Entity($attributes);

            $this->entities()->save($entity);
        }

        return $this;
    }

    public function insertEntities($entities = [])
    {
        if (empty($entities) || !is_array($entities)) {
            return $this;
        }

        $this->load(['waypoints']);

        foreach ($entities as $attributes) {
            if (isset($attributes['_import_id']) && !isset($attributes['destination_uuid'])) {
                $waypoint = $this->waypoints->firstWhere('_import_id', $attributes['_import_id']);

                if ($waypoint) {
                    $attributes['destination_uuid'] = $waypoint->uuid;
                }
            }

            // confirm destination_uuid is indeed a place record
            if (isset($attributes['destination_uuid']) && Place::where('uuid', $attributes['destination_uuid'])->doesntExist()) {
                // search waypoints for search_uuid if any
                $destination = Place::where('meta->search_uuid', $attributes['destination_uuid'])->first();

                if ($destination instanceof Place) {
                    $attributes['destination_uuid'] = $destination->uuid;
                } else {
                    unset($attributes['destination_uuid']);
                }
            }

            Entity::insertGetUuid($attributes, $this);
        }

        $this->load(['entities']);

        return $this;
    }

    public function setWaypoints($waypoints = [])
    {
        if (!is_array($waypoints)) {
            return $this;
        }

        foreach ($waypoints as $index => $attributes) {
            $waypoint = [];

            if (Utils::isset($attributes, 'place') && is_array(Utils::get($attributes, 'place'))) {
                $attributes = Utils::get($attributes, 'place');
            }

            if (is_array($attributes) && array_key_exists('place_uuid', $attributes) && Place::where('uuid', $attributes['place_uuid'])->exists()) {
                $waypoint = [
                    'place_uuid'   => $attributes['place_uuid'],
                    'payload_uuid' => $attributes['payload_uuid'] ?? null,
                    'order'        => $index,
                ];
            } else {
                $place = Place::createFromMixed($attributes);

                // if has a temporary uuid from search create meta attr for search_uuid
                if ($place instanceof Place && isset($attributes['uuid']) && $place->uuid !== $attributes['uuid']) {
                    $place->updateMeta('search_uuid', $attributes['uuid']);
                }

                $waypoint['place_uuid'] = $place->uuid;
            }

            // set payload
            $waypoint['payload_uuid'] = $this->uuid;
            $waypointRecord           = Waypoint::updateOrCreate($waypoint);

            $this->waypointMarkers->push($waypointRecord);
        }

        return $this;
    }

    public function insertWaypoints($waypoints = [])
    {
        if (!is_array($waypoints)) {
            return $this;
        }

        foreach ($waypoints as $index => $attributes) {
            $waypoint = [];

            if (Utils::isset($attributes, 'place') && is_array(Utils::get($attributes, 'place'))) {
                $attributes = Utils::get($attributes, 'place');
            }

            if (is_array($attributes) && array_key_exists('place_uuid', $attributes) && Place::where('uuid', $attributes['place_uuid'])->exists()) {
                $waypoint = [
                    'place_uuid'   => $attributes['place_uuid'],
                    'payload_uuid' => $attributes['payload_uuid'] ?? null,
                    'order'        => $index,
                ];
            } else {
                $placeUuid = Place::insertFromMixed($attributes);

                // if has a temporary uuid from search create meta attr for search_uuid
                if (Utils::isUuid($placeUuid) && isset($attributes['uuid']) && $placeUuid !== $attributes['uuid']) {
                    $place = Place::where('uuid', $placeUuid)->first();

                    if ($place instanceof Place) {
                        $place->updateMeta('search_uuid', $attributes['uuid']);
                    }
                }

                $waypoint['place_uuid'] = $placeUuid;
            }

            Waypoint::insertGetUuid($waypoint, $this);
        }

        return $this;
    }

    public function updateWaypoints($waypoints = [])
    {
        if (!is_array($waypoints)) {
            return $this;
        }

        $placeIds = [];

        // collect all place ids to insert
        foreach ($waypoints as $index => $attributes) {
            if (Utils::isset($attributes, 'place') && is_array(Utils::get($attributes, 'place'))) {
                $attributes = Utils::get($attributes, 'place');
            }

            if (is_array($attributes) && array_key_exists('place_uuid', $attributes)) {
                $placeIds[] = $attributes['place_uuid'];
            } else {
                $placeUuid  = Place::insertFromMixed($attributes);
                $placeIds[] = $placeUuid;
            }
        }

        /** @return \Illuminate\Database\Eloquent\Collection $waypointMakers */
        $waypointMakers = $this->waypointMarkers()->get();

        // remove all waypoints that are not included in the placeids
        $waypointMakers = $waypointMakers->filter(function ($waypointMarker) use ($placeIds) {
            if (!in_array($waypointMarker->place_uuid, $placeIds)) {
                $waypointMarker->delete();
            }

            return in_array($waypointMarker->place_uuid, $placeIds);
        });

        // update or create waypoint markers
        foreach ($placeIds as $placeId) {
            Waypoint::updateOrCreate(
                [
                    'payload_uuid' => $this->uuid,
                    'place_uuid'   => $placeId,
                ],
                [
                    'payload_uuid' => $this->uuid,
                    'place_uuid'   => $placeId,
                ]
            );
        }

        return $this->refresh()->load(['waypoints']);
    }

    /**
     * Get the payload pickup point or the first waypoint.
     *
     * @return \Fleetbase\Models\Place|null
     */
    public function getDropoffOrLastWaypoint(): ?Place
    {
        $this->load(['dropoff', 'waypoints']);

        if ($this->dropoff instanceof Place) {
            return $this->dropoff;
        }

        if ($this->waypoints()->count()) {
            return $this->waypoints->first();
        }

        return null;
    }

    /**
     * Get the payload pickup point or the first waypoint.
     *
     * @return \Fleetbase\Models\Place|null
     */
    public function getPickupOrFirstWaypoint(): ?Place
    {
        $this->load(['pickup', 'waypoints']);

        if ($this->pickup instanceof Place) {
            return $this->pickup;
        }

        if ($this->waypoints()->count()) {
            return $this->waypoints->last();
        }

        return null;
    }

    /**
     * Get the payload pickup point or the current waypoint.
     *
     * @return \Fleetbase\Models\Place|null
     */
    public function getPickupOrCurrentWaypoint(): ?Place
    {
        $this->load(['pickup', 'dropoff', 'waypoints']);

        if ($this->pickup instanceof Place) {
            return $this->pickup;
        }

        // special case where starting point is drivers current location
        // this special case can be set in order meta `pickup_is_driver_location`
        // this will start the order at the current location of the driver
        if ($this->hasMeta('pickup_is_driver_location')) {
            // if should use the driver location attempt to use dropoff
            if ($this->dropoff instanceof Place) {
                return $this->dropoff;
            }
        }

        // use the current waypoint
        // if the current waypoint isn't found fallback to first waypoint
        if ($this->waypoints()->count()) {
            $destination = null;

            if ($this->current_waypoint_uuid) {
                $destination = $this->waypoints->firstWhere('uuid', $this->current_waypoint_uuid);
            }

            if (!$destination) {
                $destination = $this->waypoints->first();
            }

            return $destination;
        }

        return null;
    }

    public function getPickupRegion(): string
    {
        $pickup = $this->getPickupOrCurrentWaypoint();

        return $pickup->country ?? $pickup->province ?? $pickup->district ?? 'SG';
    }

    public function getCountryCode(): string
    {
        $start = $this->getPickupOrCurrentWaypoint();

        return $start->country;
    }

    public function getAllStops()
    {
        $stops = collect();

        if ($this->pickup) {
            $stops->push($this->pickup);
        }

        if ($this->dropoff) {
            $stops->push($this->dropoff);
        }

        if ($this->waypoints) {
            foreach ($this->waypoints as $waypoint) {
                $stops->push($waypoint);
            }
        }

        return $stops->filter();
    }

    /**
     * Get the pickup location for the payload.
     *
     * @return \Grimzy\LaravelMysqlSpatial\Types\Point
     */
    public function getPickupLocation()
    {
        $pickup = $this->getPickupOrCurrentWaypoint();

        return $pickup->location ?? new Point(0, 0);
    }

    public function getOrder()
    {
        if ($this->order) {
            return $this->order;
        }

        $this->load('order');

        return $this->order;
    }

    public function setPlace($property, $place, $save = false)
    {
        if (!$place) {
            return;
        }

        $attr     = $property . '_uuid';
        $instance = Place::createFromMixed($place);

        if ($instance) {
            if (Str::isUuid($instance)) {
                $this->setAttribute($attr, $instance);
            } elseif ($instance instanceof Model) {
                $this->setAttribute($attr, $instance->uuid);
            } else {
                $this->setAttribute($attr, $instance);
            }
        }

        if ($save) {
            $this->save();
        }

        return $this;
    }

    public function setPickup($place, $save = false)
    {
        // if using the special [driver] value, set the meta `pickup_is_driver_location`
        if ($place === '[driver]') {
            $this->setMeta('pickup_is_driver_location', true);

            return;
        }

        return $this->setPlace('pickup', $place, $save);
    }

    public function setDropoff($place, $save = false)
    {
        return $this->setPlace('dropoff', $place, $save);
    }

    public function setReturn($place, $save = false)
    {
        return $this->setPlace('return', $place, $save);
    }

    public function getIsMultipleDropOrderAttribute()
    {
        return $this->waypoints && $this->waypoints->count() > 0;
    }

    /**
     * Set the first waypoint and update activity.
     *
     * @param array                                   $activity
     * @param \Grimzy\LaravelMysqlSpatial\Types\Point $location
     *
     * @return void
     */
    public function setFirstWaypoint($activity = null, $location = null)
    {
        $destination = null;

        if ($this->isMultipleDropOrder) {
            $destination = $this->waypoints->first();
        } else {
            $destination = $this->pickup ? $this->pickup : $this->waypoints->first();
        }

        if (!$destination) {
            return $this;
        }

        $this->current_waypoint_uuid = $destination->uuid;
        $this->save();
        $this->updateWaypointActivity($activity, $location);

        return $this->load('currentWaypoint');
    }

    /**
     * Update the current waypoint activity and it's entities.
     *
     * @param array                                   $activity
     * @param \Grimzy\LaravelMysqlSpatial\Types\Point $location
     * @param \Fleetbase\Models\Proof|string|null     $proof    resolvable proof of delivery/activity
     *
     * @return $this
     */
    public function updateWaypointActivity($activity = null, $location = null, $proof = null)
    {
        if ($this->isMultipleDropOrder && is_array($activity) && $location) {
            // update activity for the current waypoint
            $currentWaypoint = $this->waypointMarkers->firstWhere('place_uuid', $this->current_waypoint_uuid);

            if ($currentWaypoint) {
                $currentWaypoint->insertActivity($activity['status'], $activity['details'], $location, $activity['code'], $proof);
            }

            // update activity for all entities for this destination/waypoint
            $entities = $this->entities->where('destination_uuid', $this->current_waypoint_uuid);

            foreach ($entities as $entity) {
                $entity->insertActivity($activity['status'], $activity['details'], $location, $activity['code'], $proof);
            }
        }

        return $this;
    }

    /**
     * Set the next waypoint in sequence.
     *
     * @return void
     */
    public function setNextWaypointDestination()
    {
        $nextWaypoint = $this->waypointMarkers->filter(function ($waypoint) {
            // dump($waypoint->place->public_id, strtolower($waypoint->status_code));
            return !in_array(strtolower($waypoint->status_code), ['completed', 'canceled']) && $waypoint->place_uuid !== $this->current_waypoint_uuid;
        })->first();

        if (!$nextWaypoint) {
            return $this;
        }

        $this->current_waypoint_uuid = $nextWaypoint->place_uuid;

        if ($this->currentWaypoint) {
            $this->currentWaypoint->refresh();
        }

        return $this->load('currentWaypoint');
    }

    public function updateOrderDistanceAndTime(): ?Order
    {
        // load the order
        $this->load(['order']);

        // get the order
        $order = $this->order;

        // set google matrix based distance and time
        if ($order instanceof \Fleetbase\FleetOps\Models\Order) {
            return $order->setDistanceAndTime();
        }

        return null;
    }
}
