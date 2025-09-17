<?php

namespace Fleetbase\FleetOps\Traits;

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Support\TemplateString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait HasTrackingNumber
{
    public static function bootHasTrackingNumber(): void
    {
        static::created(function (Model $model): void {
            $trackingNumberId = TrackingNumber::insertGetUuid([
                'company_uuid' => data_get($model, 'company_uuid', session('company')),
                'owner_uuid'   => $model->uuid,
                'owner_type'   => Utils::getModelClassName($model),
                'region'       => $model->getPickupRegion(),
                'location'     => $model->getPickupLocation(),
            ], $model);

            DB::table($model->getTable())->where('uuid', $model->uuid)->update(['tracking_number_uuid' => $trackingNumberId]);

            if ($model->isFillable('tracking_number_uuid')) {
                $model->tracking_number_uuid = $trackingNumberId;
            }
        });
    }

    public function setTrackingNumber(TrackingNumber $trackingNumber)
    {
        if (!$this->isFillable('tracking_number_uuid') || Utils::notEmpty($this->tracking_number_uuid)) {
            return $this;
        }

        $this->tracking_number_uuid = $trackingNumber->uuid;
        $this->setRelation('trackingNumber', $trackingNumber);
        $this->save();

        return $this;
    }

    /**
     * Creates activity for this resource tracking number.
     *
     * @param array|Point                         $location the location of the update, can be either [lat, lng] or a \Fleetbase\LaravelMysqlSpatial\Types\Point instance
     * @param \Fleetbase\Models\Proof|string|null $proof    resolvable proof of delivery/activity
     *
     * @return \Fleetbase\Models\TrackingStatus
     */
    public function createActivity(Activity $activity, $location = [], $proof = null): TrackingStatus
    {
        $status      = $this->resolveActivityTemplateString($activity->get('status', ''));
        $details     = $this->resolveActivityTemplateString($activity->get('details', ''));
        $code        = $activity->get('code');
        $proof       = static::resolveProof($proof);
        $activity    = TrackingStatus::create([
            'company_uuid'         => data_get($this, 'company_uuid', session('company')),
            'tracking_number_uuid' => $this->tracking_number_uuid,
            'proof_uuid'           => data_get($proof, 'uuid'),
            'status'               => $status,
            'details'              => $details,
            'location'             => $this->getLocationAsPoint($location),
            'code'                 => TrackingStatus::prepareCode($code),
            'complete'             => $activity->complete(),
        ]);

        if (isset($this->trackingNumber)) {
            $this->trackingNumber->flushAttributesCache();
        }

        if (method_exists($this, 'flushAttributesCache')) {
            $this->flushAttributesCache();
        }

        return $activity;
    }

    /**
     * Inserts activity for this resource tracking number.
     *
     * @param array|Point                         $location the location of the update, can be either [lat, lng] or a \Fleetbase\LaravelMysqlSpatial\Types\Point instance
     * @param \Fleetbase\Models\Proof|string|null $proof    resolvable proof of delivery/activity
     */
    public function insertActivity(Activity $activity, $location = [], $proof = null): string
    {
        $status      = $this->resolveActivityTemplateString($activity->get('status', ''));
        $details     = $this->resolveActivityTemplateString($activity->get('details', ''));
        $code        = $activity->get('code');
        $proof       = static::resolveProof($proof);
        $activityId  = TrackingStatus::insertGetUuid([
            'company_uuid'         => data_get($this, 'company_uuid', session('company')),
            'tracking_number_uuid' => $this->tracking_number_uuid,
            'proof_uuid'           => data_get($proof, 'uuid'),
            'status'               => $status,
            'details'              => $details,
            'location'             => $this->getLocationAsPoint($location),
            'code'                 => TrackingStatus::prepareCode($code),
            'complete'             => $activity->complete(),
        ]);

        if (isset($this->trackingNumber)) {
            $this->trackingNumber->flushAttributesCache();
        }

        if (method_exists($this, 'flushAttributesCache')) {
            $this->flushAttributesCache();
        }

        return $activityId;
    }

    public function getLocationAsPoint($location)
    {
        $point = null;

        if ($location instanceof Point) {
            $point = $location;
        }

        if (is_array($location)) {
            $point =  new Point(...$location);
        }

        if ($location === null || empty($location) || !$location) {
            $point = new Point(0, 0);
        }

        return Utils::parsePointToWkt($point);
    }

    public function getPickupRegion()
    {
        if (method_exists($this, 'payload')) {
            $this->load(['payload']);

            if ($this->payload) {
                return $this->payload->getPickupRegion();
            }
        }

        return 'SG';
    }

    public function getPickupLocation()
    {
        if (method_exists($this, 'payload')) {
            $this->load(['payload']);

            if ($this->payload) {
                return $this->payload->getPickupLocation();
            }
        }

        return new Point(0, 0);
    }

    public function setStatus(?string $status, $andSave = true)
    {
        $this->status = $status;

        if ($andSave) {
            $this->save();
        }

        return $this;
    }

    /**
     * Resolves proof to model instance.
     *
     * @return \Fleetbase\Models\Proof|null
     */
    public static function resolveProof($proof)
    {
        if (is_string($proof)) {
            return Proof::where('public_id', $proof)->orWhere('uuid', $proof)->first();
        }

        if ($proof instanceof Proof) {
            return $proof;
        }

        return null;
    }

    /**
     * Resolve {placeholders} inside an activity template string.
     *
     * Behavior:
     * - If called on an Order, resolves placeholders against that Order.
     * - Otherwise, if the model exposes getOrder(), resolves against the returned Order.
     * - If no suitable target model can be determined, returns the original template unchanged.
     * - Uses App\Support\TemplateString::resolve() to process placeholders and modifiers
     *   (e.g., {waypoint.type}, {capitalize waypoint.type}, {order.number | snake | uppercase}).
     *
     * Robustness:
     * - Fast path: if there are no braces, returns early.
     * - Catches unexpected resolver failures and logs a warning rather than throwing inside UI flows.
     *
     * @param string $template the template containing {placeholders}
     *
     * @return string the resolved template string, or the original on fallback
     */
    private function resolveActivityTemplateString(string $template): string
    {
        // Fast path: no placeholders to resolve.
        if ($template === '' || strpos($template, '{') === false) {
            return $template;
        }

        // Determine which model should resolve dynamic properties.
        // Prefer $this when it's already an Order; otherwise try a getOrder() accessor.
        $target = $this instanceof Order
            ? $this
            : (method_exists($this, 'getOrder') ? $this->getOrder() : null);

        // If we couldn't locate a suitable Eloquent model, leave template unchanged.
        if (!$target instanceof Model) {
            return $template;
        }

        try {
            // Uses default resolver name 'resolveDynamicProperty' on the target model.
            return TemplateString::resolve($template, $target, 'resolveDynamicProperty');
        } catch (\Throwable $e) {
            // Don't break user flows on template issues; log and return original.
            Log::warning('Activity template resolution failed.', [
                'template'  => $template,
                'target'    => get_class($target),
                'message'   => $e->getMessage(),
            ]);

            return $template;
        }
    }
}
