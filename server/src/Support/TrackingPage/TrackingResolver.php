<?php

namespace Fleetbase\FleetOps\Support\TrackingPage;

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\TrackingScope;
use Fleetbase\Models\Setting;

/**
 * Turns what a visitor typed into the order and customer it may open, or null.
 *
 * Null covers everything a visitor must not be able to tell apart: a malformed number, an
 * unknown one, one from a company that opted out of the shared page, and, on a company's
 * own page, one belonging to another company. The caller answers all of them the same way.
 *
 * Only the `tracking_number` column is matched, never a uuid or public id.
 */
class TrackingResolver
{
    public const NUMBER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,99}$/';

    /**
     * The tracking number as typed, trimmed, or null when it can't be one.
     */
    public static function normalize(mixed $input): ?string
    {
        if (!is_string($input)) {
            return null;
        }

        $input = trim($input);

        return preg_match(static::NUMBER_PATTERN, $input) === 1 ? $input : null;
    }

    /**
     * @param string|null $slug a company page's slug; null for the shared page
     */
    public function resolve(mixed $input, ?string $slug = null): ?TrackingTarget
    {
        $number = static::normalize($input);
        if ($number === null) {
            return null;
        }

        $companyUuid = null;
        if ($slug !== null) {
            $companyUuid = $this->companyForSlug($slug);
            if ($companyUuid === null) {
                return null;
            }
        }

        $trackingNumber = $this->findTrackingNumber($number, $companyUuid);
        $target         = $trackingNumber ? $this->targetFor($trackingNumber) : null;
        if ($target === null) {
            return null;
        }

        $config = $this->config($target->companyUuid());
        if ($slug === null && (!$config['generic_page']['allowed'] || !$this->adminConfig()['generic_page']['enabled'])) {
            return null;
        }

        if ($companyUuid !== null && $target->companyUuid() !== $companyUuid) {
            return null;
        }

        return $target->withConfig($config);
    }

    /**
     * The company whose enabled page lives at this slug.
     */
    public function companyForSlug(?string $slug): ?string
    {
        $slug = strtolower(trim((string) $slug));
        if (TrackingPageConfig::slugError($slug) !== null) {
            return null;
        }

        $companyUuid = $this->lookupSetting(TrackingPageConfig::SLUG_INDEX_PREFIX . $slug);
        if (!is_string($companyUuid) || $companyUuid === '') {
            return null;
        }

        $config = $this->config($companyUuid);

        return $config['org_page']['enabled'] && $config['org_page']['slug'] === $slug ? $companyUuid : null;
    }

    /**
     * The order and customer a tracking number opens, from whatever owns it.
     */
    public function targetFor(TrackingNumber $trackingNumber): ?TrackingTarget
    {
        $owner = $this->ownerOf($trackingNumber);

        if ($owner instanceof Order) {
            return new TrackingTarget($owner, TrackingScope::forOrder($owner), $trackingNumber);
        }

        if ($owner instanceof Waypoint) {
            $order = $this->orderForPayload($owner->payload_uuid);

            return $order ? new TrackingTarget($order, TrackingScope::forStop($order, $owner), $trackingNumber, TrackingTarget::KIND_STOP, $owner) : null;
        }

        if ($owner instanceof Entity) {
            $order = $this->orderForPayload($owner->payload_uuid);
            if (!$order) {
                return null;
            }

            $waypoint = $this->waypointForEntity($owner);

            return new TrackingTarget($order, TrackingScope::forStop($order, $waypoint), $trackingNumber, TrackingTarget::KIND_ITEM, $waypoint);
        }

        if ($owner instanceof Place) {
            // A service stop's pickup or dropoff number: the stop belongs to the order's customer.
            $order = $this->orderForPlaceTrackingNumber($trackingNumber->uuid);

            return $order ? new TrackingTarget($order, TrackingScope::forOrder($order), $trackingNumber, TrackingTarget::KIND_STOP) : null;
        }

        return null;
    }

    /**
     * The slug to put in a company's tracking links, or null to link the shared page.
     */
    public function linkSlugFor(string $companyUuid): ?string
    {
        $config = $this->config($companyUuid);

        return $config['links']['target'] !== 'generic' && $config['org_page']['enabled'] ? $config['org_page']['slug'] : null;
    }

    public function config(?string $companyUuid): array
    {
        return TrackingPageConfig::sanitize(
            (array) $this->lookupSetting('company.' . $companyUuid . '.' . TrackingPageConfig::SETTING_KEY, []),
            $this->adminConfig()
        );
    }

    public function adminConfig(): array
    {
        return TrackingPageConfig::sanitizeAdmin((array) $this->lookupSetting(TrackingPageConfig::ADMIN_SETTING_KEY, []));
    }

    protected function findTrackingNumber(string $number, ?string $companyUuid): ?TrackingNumber
    {
        $query = TrackingNumber::withoutGlobalScopes()->where('tracking_number', $number);
        if ($companyUuid !== null) {
            $query->where('company_uuid', $companyUuid);
        }

        return $query->first();
    }

    protected function ownerOf(TrackingNumber $trackingNumber): mixed
    {
        return $trackingNumber->owner;
    }

    protected function orderForPayload(?string $payloadUuid): ?Order
    {
        return $payloadUuid ? Order::withoutGlobalScopes()->where('payload_uuid', $payloadUuid)->first() : null;
    }

    protected function waypointForEntity(Entity $entity): ?Waypoint
    {
        if (!$entity->payload_uuid || !$entity->destination_uuid) {
            return null;
        }

        return Waypoint::withoutGlobalScopes()->where('payload_uuid', $entity->payload_uuid)->where('place_uuid', $entity->destination_uuid)->first();
    }

    protected function orderForPlaceTrackingNumber(string $trackingNumberUuid): ?Order
    {
        $payloadUuid = Payload::withoutGlobalScopes()
            ->where('pickup_tracking_number_uuid', $trackingNumberUuid)
            ->orWhere('dropoff_tracking_number_uuid', $trackingNumberUuid)
            ->value('uuid');

        return $this->orderForPayload($payloadUuid);
    }

    protected function lookupSetting(string $key, mixed $default = null): mixed
    {
        return Setting::lookup($key, $default);
    }
}
