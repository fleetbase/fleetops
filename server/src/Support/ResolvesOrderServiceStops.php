<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Events\EntityActivityChanged;
use Fleetbase\FleetOps\Events\EntityCompleted;
use Fleetbase\FleetOps\Events\WaypointActivityChanged;
use Fleetbase\FleetOps\Events\WaypointCompleted;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Support\TemplateString;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait ResolvesOrderServiceStops
{
    protected function payloadHasWaypoints(?Payload $payload): bool
    {
        if (!$payload) {
            return false;
        }

        $payload->loadMissing('waypoints');

        return $payload->waypoints->isNotEmpty();
    }

    protected function resolveProof($proof): ?Proof
    {
        if ($proof instanceof Proof) {
            return $proof;
        }

        if (is_string($proof)) {
            return Proof::where('public_id', $proof)->orWhere('uuid', $proof)->first();
        }

        return null;
    }

    protected function payloadServiceStops(?Payload $payload): Collection
    {
        if (!$payload) {
            return collect();
        }

        $payload->loadMissing([
            'pickup',
            'dropoff',
            'return',
            'waypoints',
            'waypointMarkers.place',
            'waypointMarkers.trackingNumber.status',
        ]);

        $stops    = collect();
        $sequence = 0;

        if ($payload->pickup instanceof Place) {
            $stops->push([
                'type'                 => 'pickup',
                'place'                => $payload->pickup,
                'waypoint'             => null,
                'tracking_number_uuid' => $payload->pickup_tracking_number_uuid,
                'sequence'             => ++$sequence,
            ]);
        }

        $markers = $payload->waypointMarkers instanceof Collection ? $payload->waypointMarkers : collect();
        if ($markers->isNotEmpty()) {
            foreach ($markers->sortBy('order')->values() as $waypoint) {
                $waypoint->loadMissing('place', 'trackingNumber.status');

                if ($waypoint->place instanceof Place) {
                    $stops->push([
                        'type'                 => 'waypoint',
                        'place'                => $waypoint->place,
                        'waypoint'             => $waypoint,
                        'tracking_number_uuid' => $waypoint->tracking_number_uuid,
                        'sequence'             => ++$sequence,
                    ]);
                }
            }
        } elseif ($payload->waypoints instanceof Collection) {
            foreach ($payload->waypoints as $place) {
                if ($place instanceof Place) {
                    $stops->push([
                        'type'                 => 'waypoint',
                        'place'                => $place,
                        'waypoint'             => null,
                        'tracking_number_uuid' => null,
                        'sequence'             => ++$sequence,
                    ]);
                }
            }
        }

        if ($payload->dropoff instanceof Place) {
            $stops->push([
                'type'                 => 'dropoff',
                'place'                => $payload->dropoff,
                'waypoint'             => null,
                'tracking_number_uuid' => $payload->dropoff_tracking_number_uuid,
                'sequence'             => ++$sequence,
            ]);
        }

        if ($payload->return instanceof Place) {
            $stops->push([
                'type'                 => 'return',
                'place'                => $payload->return,
                'waypoint'             => null,
                'tracking_number_uuid' => $payload->return_tracking_number_uuid,
                'sequence'             => ++$sequence,
            ]);
        }

        return $stops->values();
    }

    protected function payloadCurrentServiceStop(?Payload $payload): ?array
    {
        $stops = $this->payloadServiceStops($payload);
        if ($stops->isEmpty()) {
            return null;
        }

        $currentStopId = $payload?->current_waypoint_uuid;
        if ($currentStopId) {
            $current = $stops->first(function (array $stop) use ($currentStopId) {
                $place    = $stop['place'] ?? null;
                $waypoint = $stop['waypoint'] ?? null;

                return $place instanceof Place && in_array($currentStopId, [$place->uuid, $place->public_id, $place->id], true)
                    || $waypoint instanceof Waypoint && in_array($currentStopId, [$waypoint->uuid, $waypoint->public_id, $waypoint->place_uuid], true);
            });

            if ($current) {
                return $current;
            }
        }

        return $stops->first();
    }

    protected function ensurePayloadCurrentServiceStop(?Payload $payload): ?array
    {
        $stop = $this->payloadCurrentServiceStop($payload);
        if (!$payload || !$stop) {
            return null;
        }

        $place = $stop['place'] ?? null;
        if ($place instanceof Place && $payload->current_waypoint_uuid !== $place->uuid) {
            $this->setPayloadCurrentServiceStop($payload, $stop);
        }

        return $stop;
    }

    protected function setPayloadCurrentServiceStop(Payload $payload, array $stop): ?Place
    {
        $place = $stop['place'] ?? null;
        if (!$place instanceof Place) {
            return null;
        }

        $payload->current_waypoint_uuid = $place->uuid;
        $payload->saveQuietly();
        $payload->setRelation('currentWaypoint', $place);

        if (($stop['waypoint'] ?? null) instanceof Waypoint) {
            $payload->setRelation('currentWaypointMarker', $stop['waypoint']);
        }

        return $place;
    }

    protected function resolveServiceStopFromKey(?Payload $payload, ?string $key = null): ?array
    {
        if (!$payload || !$key) {
            return null;
        }

        return $this->payloadServiceStops($payload)->first(function (array $stop) use ($key) {
            $place    = $stop['place'] ?? null;
            $waypoint = $stop['waypoint'] ?? null;

            return $place instanceof Place && in_array($key, [$place->uuid, $place->public_id, $place->id], true)
                || $waypoint instanceof Waypoint && in_array($key, [$waypoint->uuid, $waypoint->public_id, $waypoint->place_uuid], true);
        });
    }

    protected function nextIncompleteServiceStop(Order $order, Payload $payload): ?array
    {
        $current         = $this->payloadCurrentServiceStop($payload);
        $currentSequence = (int) data_get($current, 'sequence', 0);

        return $this->payloadServiceStops($payload)
            ->filter(fn (array $stop) => (int) data_get($stop, 'sequence', 0) > $currentSequence)
            ->first(fn (array $stop) => !$this->serviceStopIsComplete($order, $payload, $stop));
    }

    protected function serviceStopIsComplete(Order $order, Payload $payload, array $stop): bool
    {
        if (in_array($order->status, ['completed', 'canceled'], true)) {
            return true;
        }

        $type = $stop['type'] ?? null;
        if ($type === 'waypoint') {
            $waypoint = $stop['waypoint'] ?? null;

            return $waypoint instanceof Waypoint && $this->waypointMarkerIsComplete($waypoint);
        }

        $trackingNumberUuid = $this->serviceStopTrackingNumberUuid($payload, $stop);
        if ($trackingNumberUuid) {
            return (bool) data_get($this->trackingNumberStatus($trackingNumberUuid), 'complete', false);
        }

        return false;
    }

    protected function updateCurrentServiceStopActivity(Order $order, Activity $activity, $location = null, $proof = null, bool $skipEndpointOrderActivity = false, bool $updateEndpointOrderStatus = true): ?array
    {
        $payload = $order->payload;
        $stop    = $this->ensurePayloadCurrentServiceStop($payload);
        if (!$payload || !$stop || !Utils::isActivity($activity) || !$location) {
            return $stop;
        }

        $waypoint = $stop['waypoint'] ?? null;
        if ($waypoint instanceof Waypoint) {
            $payload->loadMissing(['entities']);
            $activityId = $waypoint->insertActivity($activity, $location, $proof);
            if ($activityId && $waypoint->tracking_number_uuid) {
                DB::table((new TrackingNumber())->getTable())->where('uuid', $waypoint->tracking_number_uuid)->update(['status_uuid' => $activityId]);
                if ($waypoint->relationLoaded('trackingNumber')) {
                    $waypoint->trackingNumber->status_uuid = $activityId;
                    $waypoint->trackingNumber->unsetRelation('status');
                }
            }
            $activity->fireEvents($order, $waypoint);

            $entities = $payload->entities->where('destination_uuid', $waypoint->place_uuid);
            foreach ($entities as $entity) {
                $entity->insertActivity($activity, $location, $proof);
                if ($activity->complete()) {
                    event(new EntityCompleted($entity, $activity));
                } else {
                    event(new EntityActivityChanged($entity, $activity));
                }
            }

            if ($activity->complete()) {
                event(new WaypointCompleted($waypoint, $activity));
            } else {
                event(new WaypointActivityChanged($waypoint, $activity));
            }

            return $stop;
        }

        if (!$skipEndpointOrderActivity) {
            $this->insertEndpointServiceStopActivity($order, $payload, $stop, $activity, $location, $proof);
        }

        return $stop;
    }

    protected function advanceCurrentServiceStopDestination(Order $order, Payload $payload): ?array
    {
        $nextStop = $this->nextIncompleteServiceStop($order, $payload);
        if (!$nextStop) {
            return null;
        }

        $this->setPayloadCurrentServiceStop($payload, $nextStop);

        return $nextStop;
    }

    protected function payloadHasCurrentServiceStopActivity(?Payload $payload, Activity $activity): bool
    {
        if (!$payload || !Utils::isActivity($activity)) {
            return false;
        }

        $stop = $this->payloadCurrentServiceStop($payload);
        if (!$stop) {
            return false;
        }

        $waypoint = $stop['waypoint'] ?? null;
        if ($waypoint instanceof Waypoint) {
            if (!$waypoint->tracking_number_uuid) {
                return false;
            }

            return TrackingStatus::where('tracking_number_uuid', $waypoint->tracking_number_uuid)
                ->where('code', TrackingStatus::prepareCode($activity->code))
                ->exists();
        }

        $trackingNumberUuid = $this->serviceStopTrackingNumberUuid($payload, $stop);
        if (!$trackingNumberUuid) {
            return false;
        }

        return TrackingStatus::where('tracking_number_uuid', $trackingNumberUuid)
            ->where('code', TrackingStatus::prepareCode($activity->code))
            ->exists();
    }

    protected function serviceStopTrackingNumberUuid(Payload $payload, array $stop): ?string
    {
        $waypoint = $stop['waypoint'] ?? null;
        if ($waypoint instanceof Waypoint) {
            return $waypoint->tracking_number_uuid;
        }

        $column = $this->endpointTrackingNumberColumn($stop['type'] ?? null);
        if (!$column) {
            return null;
        }

        return $payload->{$column} ?: null;
    }

    protected function endpointTrackingNumberColumn(?string $type): ?string
    {
        return match ($type) {
            'pickup'  => 'pickup_tracking_number_uuid',
            'dropoff' => 'dropoff_tracking_number_uuid',
            'return'  => 'return_tracking_number_uuid',
            default   => null,
        };
    }

    protected function endpointServiceStopTrackingNumber(Order $order, Payload $payload, array $stop, bool $create = false): ?TrackingNumber
    {
        $column = $this->endpointTrackingNumberColumn($stop['type'] ?? null);
        $place  = $stop['place'] ?? null;
        if (!$column || !$place instanceof Place) {
            return null;
        }

        $trackingNumberUuid = $payload->{$column};
        if ($trackingNumberUuid) {
            $trackingNumber = TrackingNumber::where('uuid', $trackingNumberUuid)->first();
            if ($trackingNumber) {
                return $trackingNumber;
            }
        }

        if (!$create) {
            return null;
        }

        $trackingNumberUuid = TrackingNumber::insertGetUuid([
            'company_uuid' => $payload->company_uuid ?? $order->company_uuid,
            'region'       => strtoupper($place->country ?: $place->province ?: 'SG'),
            'location'     => Utils::parsePointToWkt($this->serviceStopLocationPoint($place)),
        ], $place);

        if (!$trackingNumberUuid) {
            return null;
        }

        DB::table($payload->getTable())->where('uuid', $payload->uuid)->update([$column => $trackingNumberUuid]);
        $payload->{$column} = $trackingNumberUuid;

        return TrackingNumber::where('uuid', $trackingNumberUuid)->first();
    }

    protected function insertEndpointServiceStopActivity(Order $order, Payload $payload, array $stop, Activity $activity, $location = null, $proof = null): ?string
    {
        $trackingNumber = $this->endpointServiceStopTrackingNumber($order, $payload, $stop, true);
        if (!$trackingNumber) {
            return null;
        }

        $proof      = $this->resolveProof($proof);
        $activityId = TrackingStatus::insertGetUuid([
            'company_uuid'         => $payload->company_uuid ?? $order->company_uuid,
            'tracking_number_uuid' => $trackingNumber->uuid,
            'proof_uuid'           => data_get($proof, 'uuid'),
            'status'               => TemplateString::resolve($activity->get('status', ''), $order),
            'details'              => TemplateString::resolve($activity->get('details', ''), $order),
            'location'             => Utils::parsePointToWkt($this->activityLocationPoint($location)),
            'code'                 => TrackingStatus::prepareCode($activity->get('code')),
            'complete'             => $activity->complete(),
        ]);

        if ($activityId) {
            DB::table($trackingNumber->getTable())->where('uuid', $trackingNumber->uuid)->update(['status_uuid' => $activityId]);
            $trackingNumber->status_uuid = $activityId;
        }

        if ($trackingNumber->relationLoaded('status')) {
            $trackingNumber->unsetRelation('status');
        }

        return $activityId ?: null;
    }

    protected function serviceStopActivityContext(Order $order, Payload $payload, array $stop): Order|Waypoint
    {
        $waypoint = $stop['waypoint'] ?? null;
        if ($waypoint instanceof Waypoint) {
            return $waypoint;
        }

        $trackingNumber = $this->endpointServiceStopTrackingNumber($order, $payload, $stop);
        $context        = new Waypoint([
            'company_uuid'          => $payload->company_uuid ?? $order->company_uuid,
            'payload_uuid'          => $payload->uuid,
            'place_uuid'            => data_get($stop, 'place.uuid'),
            'tracking_number_uuid'  => data_get($trackingNumber, 'uuid'),
            'type'                  => $stop['type'] ?? null,
            'order'                 => $stop['sequence'] ?? null,
        ]);

        if ($trackingNumber) {
            $trackingNumber->loadMissing('status');
            $context->setRelation('trackingNumber', $trackingNumber);
        }

        return $context;
    }

    protected function nextActivitiesForServiceStop(Order $order, Payload $payload, array $stop): Collection
    {
        $orderConfig = $order->ensureOrderConfig();
        if (!$orderConfig) {
            return collect();
        }

        $waypoint = $stop['waypoint'] ?? null;
        if ($waypoint instanceof Waypoint) {
            return $orderConfig->nextActivity($waypoint);
        }

        $trackingNumberUuid = $this->serviceStopTrackingNumberUuid($payload, $stop);
        $currentStatus      = $trackingNumberUuid ? $this->trackingNumberStatus($trackingNumberUuid) : null;
        $currentCode        = $currentStatus
            ? $currentStatus->code
            : 'created';

        $currentActivity = $orderConfig->activities()->firstWhere('code', strtolower((string) $currentCode));
        if (!$currentActivity) {
            return collect();
        }

        return $currentActivity->getNext($this->serviceStopActivityContext($order, $payload, $stop));
    }

    protected function trackingNumberStatus(string $trackingNumberUuid): ?TrackingStatus
    {
        $trackingNumber = TrackingNumber::where('uuid', $trackingNumberUuid)->first();
        if (!$trackingNumber) {
            return null;
        }

        if ($trackingNumber->status_uuid) {
            return TrackingStatus::where('uuid', $trackingNumber->status_uuid)->first();
        }

        return TrackingStatus::where('tracking_number_uuid', $trackingNumberUuid)->latest()->first();
    }

    protected function serviceStopLocationPoint(Place $place): Point
    {
        try {
            $point = Utils::getPointFromMixed($place);

            return $point instanceof Point ? $point : new Point(0, 0);
        } catch (\Throwable) {
            return new Point(0, 0);
        }
    }

    protected function activityLocationPoint($location): Point
    {
        if ($location instanceof Point) {
            return $location;
        }

        if (is_array($location)) {
            return new Point(...$location);
        }

        return new Point(0, 0);
    }

    protected function waypointMarkerIsComplete(Waypoint $waypoint): bool
    {
        if (!$waypoint->tracking_number_uuid) {
            return false;
        }

        return (bool) data_get($this->trackingNumberStatus($waypoint->tracking_number_uuid), 'complete', false);
    }
}
